import { useEffect, useState } from 'react';
import { Bot, Copy, KeyRound, Trash2, Plus, Radio } from 'lucide-react';
import { useStore } from '../store';
import type { ApiStatus } from '../config';
import { Section, Select, Field, Seg } from './ui';
import { t } from '../i18n';

const LEVELS = [
  { value: 'read', label: 'Read only' },
  { value: 'draft', label: 'Propose (draft)' },
  { value: 'live', label: 'Edit live' },
  { value: 'admin', label: 'Full control' },
];

const copy = (text: string) => {
  void navigator.clipboard?.writeText(text);
  useStore.getState().toast(t('Copied.'), 'success');
};

export const AiPanel = () => {
  const cfg = useStore((s) => s.cfg);
  const api = useStore((s) => s.api);
  const activity = useStore((s) => s.activity);
  const draft = useStore((s) => s.draft);
  const [status, setStatus] = useState<ApiStatus | null>(cfg.api);
  const [label, setLabel] = useState('Claude');
  const [level, setLevel] = useState('draft');
  const [fresh, setFresh] = useState<string | null>(null);
  const [about, setAbout] = useState<string | null>(null);
  const [client, setClient] = useState<'claude-code' | 'claude-desktop' | 'cursor' | 'other'>('claude-code');

  useEffect(() => {
    if (cfg.user.admin) void api.get<ApiStatus>('api').then(setStatus);
  }, [api, cfg.user.admin]);

  const endpoint = status?.endpoint ?? cfg.restUrl + 'mcp';
  const key = fresh ?? 'YOUR_KEY';
  const name = status?.serverName ?? 'visual-editor';
  const snippets = {
    'claude-code': `claude mcp add --transport http ${name} ${endpoint} --header "Authorization: Bearer ${key}"`,
    'claude-desktop': JSON.stringify({ mcpServers: { [name]: { command: 'npx', args: ['-y', 'mcp-remote@latest', endpoint, '--header', `Authorization:Bearer ${key}`] } } }, null, 2),
    cursor: JSON.stringify({ mcpServers: { [name]: { url: endpoint, headers: { Authorization: `Bearer ${key}` } } } }, null, 2),
    other: `URL: ${endpoint}\nTransport: Streamable HTTP (JSON)\nHeader: Authorization: Bearer ${key}\n(or X-SVE-Key: ${key})`,
  };

  return (
    <div className="ve-scroll">
      <div className="ve-pad" style={{ display: 'grid', gap: 8 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <Bot size={18} color="#5eead4" />
          <b>{t('Build together with AI')}</b>
        </div>
        <p className="ve-faint" style={{ margin: 0 }}>
          {t('Connect Claude (or any MCP client) to this site. The AI sees pages like you do, edits styles, layouts, products and templates, and shows you what it means — right here.')}
        </p>
        <div className="ve-chip ai" style={{ width: 'fit-content' }}>
          <Radio size={12} /> {t('This editor answers the AI’s screenshot and inspect requests while it is open.')}
        </div>
      </div>

      {draft > 0 && (
        <Section title={t('Waiting for your review')} hint={String(draft)}>
          <p className="ve-faint" style={{ margin: 0 }}>{t('An AI key with the draft level proposed {n} changes.', { n: draft })}</p>
          <button className="ve-btn ai" onClick={() => useStore.setState({ modal: { type: 'draft' } })}>
            {t('Review AI changes')}
          </button>
        </Section>
      )}

      {cfg.user.admin && status && (
        <Section title={t('Access keys')}>
          <label className="ve-check">
            <input type="checkbox" checked={status.enabled} onChange={async (e) => setStatus(await api.put<ApiStatus>('api', { enabled: e.target.checked }))} /> {t('AI access enabled')}
          </label>
          {status.keys.map((k) => (
            <div key={k.id} className="ve-item" style={{ cursor: 'default' }}>
              <KeyRound size={14} />
              <span style={{ display: 'grid', minWidth: 0 }}>
                <span className="t">{k.label} <span className="ve-faint">{k.hint}</span></span>
                <span className="ve-faint">{k.user} · {k.calls} {t('calls')}{k.lastUsed ? ' · ' + new Date(k.lastUsed * 1000).toLocaleString() : ''}</span>
              </span>
              <select className="ve-select" style={{ width: 120, marginInlineStart: 'auto' }} value={k.level} onChange={async (e) => setStatus(await api.put<ApiStatus>(`api/keys/${k.id}`, { level: e.target.value }))}>
                {LEVELS.map((l) => (
                  <option key={l.value} value={l.value}>{t(l.label)}</option>
                ))}
              </select>
              <button className="ve-icon-btn" title={t('Revoke')} onClick={async () => { if (window.confirm(t('Revoke key “{name}”? Clients using it stop working.', { name: k.label }))) setStatus(await api.del<ApiStatus>(`api/keys/${k.id}`)); }}>
                <Trash2 size={13} />
              </button>
            </div>
          ))}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 130px auto', gap: 4 }}>
            <Field value={label} onChange={setLabel} placeholder={t('Name')} />
            <Select value={level} onChange={setLevel} options={LEVELS.map((l) => ({ value: l.value, label: t(l.label) }))} />
            <button
              className="ve-btn small primary"
              onClick={async () => {
                const r = await api.post<ApiStatus & { key: string }>('api/keys', { label, level });
                setFresh(r.key);
                setStatus(r);
              }}
            >
              <Plus size={13} />
            </button>
          </div>
          <div className="ve-faint">
            {level === 'read' && t('Can look at everything, change nothing.')}
            {level === 'draft' && t('Changes wait here until you publish them. Safest for working together.')}
            {level === 'live' && t('Changes go live at once (each one keeps a revision). No scripts.')}
            {level === 'admin' && t('Everything you can do: scripts, plugins, settings, any WordPress API. Only for clients you fully trust.')}
          </div>
          <Field value={about ?? status.about} onChange={setAbout} placeholder={t('Describe this site to the AI (shown when it connects)')} />
          {about !== null && about !== status.about && (
            <button className="ve-btn small" onClick={async () => { setStatus(await api.put<ApiStatus>('api', { enabled: status.enabled, about })); setAbout(null); }}>
              {t('Save')}
            </button>
          )}
          {fresh && (
            <div style={{ display: 'grid', gap: 4 }}>
              <b style={{ color: '#5eead4' }}>{t('Copy the key now — it is shown only once:')}</b>
              <div className="ve-code-snippet" onClick={() => copy(fresh)}>{fresh}</div>
            </div>
          )}
        </Section>
      )}

      <Section title={t('Connect a client')}>
        <Seg value={client} onChange={setClient} options={[{ value: 'claude-code', label: 'Claude Code' }, { value: 'claude-desktop', label: 'Claude Desktop' }, { value: 'cursor', label: 'Cursor' }, { value: 'other', label: t('Other') }]} />
        <div className="ve-code-snippet" style={{ position: 'relative' }}>
          {snippets[client]}
          <button className="ve-icon-btn" style={{ position: 'absolute', top: 2, insetInlineEnd: 2 }} onClick={() => copy(snippets[client])}>
            <Copy size={13} />
          </button>
        </div>
        <div className="ve-faint">
          {client === 'claude-desktop' && t('Settings → Developer → Edit config, paste, restart Claude Desktop (needs Node.js).')}
          {client === 'claude-code' && t('Run in a terminal. Then ask: “look at the home page and make the hero more modern”.')}
          {client === 'cursor' && t('Put it in .cursor/mcp.json.')}
        </div>
        <div className="ve-faint">{t('Web apps such as Claude.ai: add a custom connector with the address above — no key needed, you approve the connection on a page of this site.')}</div>
        {cfg.user.admin && status && status.apps.length > 0 && (
          <div style={{ display: 'grid', gap: 4 }}>
            {status.apps.map((a) => (
              <div key={a.name + a.created} className="ve-faint">🔗 {a.name} · {new Date(a.last_used * 1000).toLocaleDateString()}</div>
            ))}
            <button className="ve-btn small" onClick={async () => { if (window.confirm(t('Disconnect every app? They have to be approved again.'))) setStatus(await api.del<ApiStatus>('api/apps')); }}>
              {t('Disconnect all apps')}
            </button>
          </div>
        )}
        <div className="ve-faint">{t('Try: “Find the Book now button on /tours/ and make it rounded with our primary color” · “Create a product page template with gallery, price and related tours” · “Build a landing page for Phuket in Persian, mobile first”.')}</div>
      </Section>

      <Section title={t('Activity')} hint={String(activity.length)}>
        <div className="ve-feed">
          {activity.length === 0 && <div className="ve-faint">{t('Nothing yet.')}</div>}
          {[...activity].reverse().slice(0, 60).map((a) => (
            <div key={a.id} className={'ev' + (a.actor.startsWith('ai:') ? ' ai' : '')}>
              <span>{a.summary}</span>
              <small>
                {a.actor.replace(/^ai:/, '🤖 ').replace(/^user:/, '👤 ')} · {new Date(a.time * 1000).toLocaleTimeString()}
              </small>
            </div>
          ))}
        </div>
      </Section>
    </div>
  );
};
