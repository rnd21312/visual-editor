import { useCallback, useEffect, useState } from 'react';
import type { Api } from '@/admin/lib/api';
import type { VisualDoc } from '@/lib/visualCss';
import type { ApiStatus } from './types';

type Revision = { id: number; time: number; edits: number; ops: number };

type Props = {
  api: Api;
  initial: ApiStatus | null;
  /** A restored revision becomes the live document. */
  onRestore: (doc: VisualDoc) => void;
  /** Show the AI's proposed changes in the editor. */
  onPreviewDraft: () => void;
  onDiscardDraft: () => void;
  onClose: () => void;
};

const LEVELS: { value: ApiStatus['level']; label: string; hint: string }[] = [
  { value: 'read', label: 'Read only', hint: 'The AI can look at pages and settings but change nothing.' },
  { value: 'draft', label: 'Propose changes — I approve them (recommended)', hint: 'Changes are saved as a draft. Nothing goes live until you review and save them here.' },
  { value: 'live', label: 'Edit live', hint: 'Changes are published immediately. Every one keeps a version you can restore.' },
];

const when = (seconds: number): string => (seconds ? new Date(seconds * 1000).toLocaleString() : '—');

const copy = (text: string) => void navigator.clipboard?.writeText(text).catch(() => undefined);

/** A read-only box with a copy button. */
const Snippet = ({ label, text }: { label: string; text: string }) => (
  <div className="ve-snippet">
    <div className="ve-snippet__head">
      <span>{label}</span>
      <button type="button" className="ve-btn ve-mini" onClick={() => copy(text)}>
        Copy
      </button>
    </div>
    <pre>{text}</pre>
  </div>
);

/**
 * Settings for AI access: turn the API on, create the key (shown once), pick what the key may do, copy
 * ready-made connection snippets, review proposed changes and roll back to an earlier version.
 */
export const ApiModal = ({ api, initial, onRestore, onPreviewDraft, onDiscardDraft, onClose }: Props) => {
  const [status, setStatus] = useState<ApiStatus | null>(initial);
  const [key, setKey] = useState('');
  const [revisions, setRevisions] = useState<Revision[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const refresh = useCallback(() => {
    api.get<ApiStatus>('admin/api').then(setStatus).catch(() => setError('Could not load the API settings.'));
    api.get<Revision[]>('admin/revisions').then(setRevisions).catch(() => undefined);
  }, [api]);

  useEffect(refresh, [refresh]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const run = async (job: () => Promise<void>) => {
    setBusy(true);
    setError('');
    try {
      await job();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Something went wrong.');
    } finally {
      setBusy(false);
    }
  };

  const configure = (change: Partial<Pick<ApiStatus, 'enabled' | 'level'>>) =>
    run(async () => {
      if (!status) return;
      setStatus(await api.put<ApiStatus>('admin/api', { enabled: status.enabled, level: status.level, ...change }));
    });

  const create = () =>
    run(async () => {
      const result = await api.post<ApiStatus & { key: string }>('admin/api/key', {});
      setKey(result.key);
      setStatus(result);
    });

  const revoke = () =>
    run(async () => {
      if (!window.confirm('Revoke the key? Every AI tool using it stops working immediately.')) return;
      setKey('');
      setStatus(await api.del<ApiStatus>('admin/api/key'));
    });

  const restore = (id: number) =>
    run(async () => {
      if (!window.confirm('Make this older version the live one? The current version is kept as a revision.')) return;
      onRestore(await api.post<VisualDoc>(`admin/revisions/${id}/restore`, {}));
      refresh();
    });

  const shownKey = key || 'YOUR_API_KEY';
  const endpoint = status?.endpoint ?? '';
  const claude = `claude mcp add --transport http suntourz-editor ${endpoint} --header "Authorization: Bearer ${shownKey}"`;
  const json = JSON.stringify({ mcpServers: { 'suntourz-editor': { type: 'http', url: endpoint, headers: { Authorization: `Bearer ${shownKey}` } } } }, null, 2);
  const curl = `curl -X POST ${endpoint} \\\n  -H "Authorization: Bearer ${shownKey}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'`;

  return (
    <div className="ve-modal" role="presentation" onPointerDown={(event) => event.target === event.currentTarget && onClose()}>
      <div className="ve-modal__box ve-api" role="dialog" aria-modal="true" aria-label="AI access">
        <header className="ve-modal__head">
          <h2>AI access (API)</h2>
          <button type="button" className="ve-icon-btn" aria-label="Close" onClick={onClose}>
            ×
          </button>
        </header>

        <div className="ve-api__body">
          <p className="ve-note">
            Lets AI assistants (Claude, ChatGPT-style agents, any MCP client) browse your pages and templates and edit them
            through this editor: styles, text, new elements, SEO. It uses the same checks as the editor itself — no PHP, no
            scripts, no access to anything outside the editor.
          </p>

          {error && <p className="ve-hint ve-hint--warn">{error}</p>}

          {!status ? (
            <p className="ve-note">Loading…</p>
          ) : (
            <>
              <section>
                <h3>1 · API key</h3>
                {status.hasKey ? (
                  <p>
                    Key <code>{status.keyHint}</code> · created {when(status.created)} · last used {when(status.lastUsed)} · {status.calls} calls
                  </p>
                ) : (
                  <p className="ve-note">No key yet.</p>
                )}
                {key && (
                  <div className="ve-keybox" role="status">
                    <strong>Copy your key now — it will not be shown again.</strong>
                    <code>{key}</code>
                    <button type="button" className="ve-btn ve-btn--primary" onClick={() => copy(key)}>
                      Copy key
                    </button>
                  </div>
                )}
                <div className="ve-row-actions">
                  <button type="button" className="ve-btn ve-btn--primary" disabled={busy} onClick={() => void create()}>
                    {status.hasKey ? 'Create a new key (replaces the old one)' : 'Create API key'}
                  </button>
                  {status.hasKey && (
                    <button type="button" className="ve-btn" disabled={busy} onClick={() => void revoke()}>
                      Revoke key
                    </button>
                  )}
                </div>
              </section>

              <section>
                <h3>2 · What the key may do</h3>
                <label className="ve-check">
                  <input type="checkbox" checked={status.enabled} disabled={busy || !status.hasKey} onChange={(event) => void configure({ enabled: event.target.checked })} />
                  API switched on
                </label>
                {LEVELS.map((level) => (
                  <label key={level.value} className="ve-radio">
                    <input type="radio" name="level" checked={status.level === level.value} disabled={busy} onChange={() => void configure({ level: level.value })} />
                    <span>
                      <strong>{level.label}</strong>
                      <small>{level.hint}</small>
                    </span>
                  </label>
                ))}
                <p className="ve-hint">
                  Limits: {status.limits.readsPerMinute} reads and {status.limits.writesPerMinute} changes per minute, {status.limits.writesPerDay} changes per
                  day. Wrong keys are blocked after a few tries.
                </p>
              </section>

              {(status.draftChanges ?? 0) > 0 && (
                <section className="ve-api__draft">
                  <h3>Waiting for your approval</h3>
                  <p>
                    The AI proposed <strong>{status.draftChanges}</strong> change{status.draftChanges === 1 ? '' : 's'}.
                  </p>
                  <div className="ve-row-actions">
                    <button type="button" className="ve-btn ve-btn--primary" onClick={onPreviewDraft}>
                      Review in the editor
                    </button>
                    <button type="button" className="ve-btn" onClick={onDiscardDraft}>
                      Discard
                    </button>
                  </div>
                </section>
              )}

              <section>
                <h3>3 · Connect an AI tool</h3>
                <p className="ve-hint">The endpoint speaks MCP (Model Context Protocol) over HTTPS and also plain JSON-RPC.</p>
                <Snippet label="Endpoint" text={endpoint} />
                <Snippet label="Claude Code" text={claude} />
                <Snippet label="Any MCP client (JSON config)" text={json} />
                <Snippet label="Test with curl" text={curl} />
              </section>

              <section>
                <h3>Earlier versions</h3>
                {revisions.length === 0 ? (
                  <p className="ve-note">Every time you save (or an AI publishes) the previous version is kept here.</p>
                ) : (
                  <ul className="ve-revisions">
                    {revisions.map((revision) => (
                      <li key={revision.id}>
                        <span>
                          {when(revision.time)} <small>· {revision.edits} edits, {revision.ops} structure changes</small>
                        </span>
                        <button type="button" className="ve-btn ve-mini" disabled={busy} onClick={() => void restore(revision.id)}>
                          Restore
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            </>
          )}
        </div>
      </div>
    </div>
  );
};
