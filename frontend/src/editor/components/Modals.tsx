import { useEffect, useRef, useState } from 'react';
import { Upload, Download, RotateCcw } from 'lucide-react';
import { useStore } from '../store';
import { Modal, Row, Seg, Select } from './ui';
import { ComponentEditor } from './ComponentsPanel';
import { NewContentModal, NewTemplateModal } from './PagesPanel';
import type { Doc } from '../../shared/types';
import { t } from '../i18n';

export const Modals = () => {
  const modal = useStore((s) => s.modal);
  if (!modal) return null;
  switch (modal.type) {
    case 'settings':
      return <SettingsModal />;
    case 'revisions':
      return <RevisionsModal />;
    case 'draft':
      return <DraftModal />;
    case 'component':
      return <ComponentEditor id={modal.id} />;
    case 'new-content':
      return <NewContentModal postType={modal.postType} />;
    case 'new-template':
      return <NewTemplateModal />;
    case 'media':
      return <MediaModal onPick={modal.onPick} />;
    case 'shortcuts':
      return <ShortcutsModal />;
    default:
      return null;
  }
};

const close = () => useStore.setState({ modal: null });

const SettingsModal = () => {
  const doc = useStore((s) => s.doc);
  const cfg = useStore((s) => s.cfg);
  const setDoc = useStore((s) => s.setDoc);
  const file = useRef<HTMLInputElement>(null);
  const exportDoc = () => {
    const blob = new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `visual-editor-${new Date().toISOString().slice(0, 10)}.json`;
    a.click();
  };
  const importDoc = async (f: File) => {
    try {
      const data = JSON.parse(await f.text()) as Doc;
      if (!Array.isArray(data.edits)) throw new Error('Not an editor export.');
      if (!window.confirm(t('Replace the current design (styles, elements, components, SEO) with this file? You can undo before publishing.'))) return;
      setDoc(() => ({ ...data, rev: doc.rev }));
    } catch (e) {
      useStore.getState().toast(e instanceof Error ? e.message : String(e), 'error');
    }
  };
  return (
    <Modal title={t('Settings')} onClose={close} footer={<button className="ve-btn primary" onClick={close}>{t('Done')}</button>}>
      {cfg.user.admin && (
        <Row label={t('Who can edit')}>
          <Seg value={doc.access} onChange={(v) => setDoc((d) => void (d.access = v))} options={[{ value: 'admin', label: t('Administrators') }, { value: 'editor', label: t('Editors too') }]} />
        </Row>
      )}
      <Row label={t('Server rendering')}>
        <Select value={doc.settings.ssr} onChange={(v) => setDoc((d) => void (d.settings.ssr = v as Doc['settings']['ssr']))} options={[{ value: 'auto', label: t('Automatic (recommended)') }, { value: 'off', label: t('Off — apply edits in the browser only') }]} />
      </Row>
      <div className="ve-faint">{cfg.ssr ? t('This server applies text and structure edits in the HTML itself (PHP 8.4+): best for SEO and no flicker.') : t('This server runs PHP older than 8.4: text and structure edits are applied in the browser (styles are always server-side).')}</div>
      <label className="ve-check">
        <input type="checkbox" checked={doc.settings.reducedMotion} onChange={(e) => setDoc((d) => void (d.settings.reducedMotion = e.target.checked))} /> {t('Respect “reduce motion” of visitors (no animations for them)')}
      </label>
      <div className="ve-sep" />
      <div style={{ display: 'flex', gap: 8 }}>
        <button className="ve-btn" onClick={exportDoc}>
          <Download size={14} /> {t('Export design')}
        </button>
        <button className="ve-btn" onClick={() => file.current?.click()}>
          <Upload size={14} /> {t('Import design')}
        </button>
        <input ref={file} type="file" accept="application/json" hidden onChange={(e) => e.target.files?.[0] && void importDoc(e.target.files[0])} />
      </div>
      <div className="ve-faint">Visual Editor {cfg.version} · React {cfg.react} · {doc.edits.length} {t('styles')} · {doc.ops.length} {t('element changes')} · {doc.components.length} {t('components')}</div>
    </Modal>
  );
};

type Revision = { id: string; time: number; by: string; label: string; rev: number; edits: number; ops: number; components: number };

const RevisionsModal = () => {
  const api = useStore((s) => s.api);
  const cfg = useStore((s) => s.cfg);
  const [list, setList] = useState<Revision[]>([]);
  useEffect(() => void api.get<Revision[]>('revisions').then(setList), [api]);
  const restore = async (r: Revision) => {
    if (useStore.getState().dirty() && !window.confirm(t('You have unsaved changes. Restoring replaces them. Continue?'))) return;
    const doc = await api.post<Doc>(`revisions/${r.id}/restore`);
    useStore.setState({ doc, base: JSON.parse(JSON.stringify(doc)) });
    useStore.getState().toast(t('Restored.'), 'success');
    useStore.getState().reload();
    close();
  };
  return (
    <Modal title={t('History')} onClose={close}>
      <p className="ve-faint" style={{ margin: 0 }}>{t('Every publish (by you or an AI) keeps the previous version. Builder layouts also keep WordPress revisions.')}</p>
      <div className="ve-list">
        {list.map((r) => (
          <div key={r.id} className="ve-item" style={{ cursor: 'default' }}>
            <span style={{ display: 'grid' }}>
              <span>{new Date(r.time * 1000).toLocaleString()} <span className="ve-faint">rev {r.rev}</span></span>
              <span className="ve-faint">{r.by.replace(/^ai:/, '🤖 ').replace(/^user:/, '👤 ')} {r.label && '· ' + r.label}</span>
            </span>
            <span className="meta">{r.edits} / {r.ops} / {r.components}</span>
            {cfg.user.admin && (
              <button className="ve-btn small" onClick={() => void restore(r)}>
                <RotateCcw size={13} /> {t('Restore')}
              </button>
            )}
          </div>
        ))}
        {list.length === 0 && <div className="ve-faint">{t('No earlier versions yet.')}</div>}
      </div>
    </Modal>
  );
};

type DraftInfo = { doc: Doc | null; changes: { type: string; action: string; id: string; label: string }[]; layouts: { id: number; title: string; url: string }[] };

const DraftModal = () => {
  const api = useStore((s) => s.api);
  const cfg = useStore((s) => s.cfg);
  const [info, setInfo] = useState<DraftInfo | null>(null);
  const load = () => void api.get<DraftInfo>('draft').then(setInfo);
  useEffect(load, [api]);
  const publish = async () => {
    const doc = await api.post<Doc>('draft/publish');
    useStore.setState({ doc, base: JSON.parse(JSON.stringify(doc)), draft: 0 });
    useStore.getState().toast(t('AI changes published.'), 'success');
    useStore.getState().reload();
    load();
  };
  const preview = () => {
    if (!info?.doc) return;
    // Load the proposal into the editor: review it in the preview, adjust, then publish normally.
    const draftDoc = info.doc;
    useStore.getState().setDoc(() => ({ ...draftDoc, rev: useStore.getState().base.rev }));
    useStore.getState().toast(t('The AI draft is now in the editor. Publish to accept it, Discard to drop it.'), 'info');
    close();
  };
  const discard = async () => {
    await api.del('draft');
    useStore.setState({ draft: 0 });
    load();
  };
  const reviewLayout = async (id: number) => {
    const post = await api.get<{ builder: { draft: string; mode: string } | null }>(`content/${id}`);
    const src = useStore.getState().sources[id] ?? (await useStore.getState().loadSource(id));
    if (!post.builder?.draft || !src) return;
    useStore.getState().setSourceText(id, post.builder.draft);
    await api.del(`content/${id}/draft`);
    const url = info?.layouts.find((l) => l.id === id)?.url;
    if (url) useStore.getState().navigate(url);
    close();
  };
  if (!info) return <Modal title={t('AI proposals')} onClose={close}>{t('Loading…')}</Modal>;
  return (
    <Modal
      title={t('AI proposals')}
      onClose={close}
      footer={
        info.doc ? (
          <>
            <button className="ve-btn danger" onClick={() => void discard()}>{t('Discard all')}</button>
            <button className="ve-btn" onClick={preview}>{t('Open in editor')}</button>
            {cfg.user.admin && <button className="ve-btn ai" onClick={() => void publish()}>{t('Publish now')}</button>}
          </>
        ) : undefined
      }
    >
      {info.changes.length === 0 && info.layouts.length === 0 && <div className="ve-faint">{t('Nothing is waiting.')}</div>}
      {info.changes.length > 0 && (
        <div className="ve-list">
          {info.changes.map((c) => (
            <div key={c.type + c.id} className="ve-item" style={{ cursor: 'default' }}>
              <span className={'ve-chip ' + (c.action === 'removed' ? '' : 'ai')}>{t(c.action)}</span>
              <span className="t">{c.label}</span>
              <span className="meta">{c.type}</span>
            </div>
          ))}
        </div>
      )}
      {info.layouts.length > 0 && (
        <>
          <div className="ve-h">{t('Proposed page layouts')}</div>
          {info.layouts.map((l) => (
            <div key={l.id} className="ve-item" onClick={() => void reviewLayout(l.id)}>
              <span className="t">{l.title}</span>
              <span className="meta">{t('Open to review')}</span>
            </div>
          ))}
        </>
      )}
    </Modal>
  );
};

type Media = { id: number; title: string; url: string; thumb: string; alt: string; mime: string };

const MediaModal = ({ onPick }: { onPick: (m: { id: number; url: string; alt: string }) => void }) => {
  const api = useStore((s) => s.api);
  const [items, setItems] = useState<Media[]>([]);
  const [q, setQ] = useState('');
  const [url, setUrl] = useState('');
  const [busy, setBusy] = useState(false);
  const file = useRef<HTMLInputElement>(null);
  const load = () => void api.get<{ items: Media[] }>('media', { search: q }).then((r) => setItems(r.items));
  useEffect(() => {
    const id = window.setTimeout(load, 200);
    return () => window.clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);
  const pick = (m: { id: number; url: string; alt?: string }) => {
    onPick({ id: m.id, url: m.url, alt: m.alt ?? '' });
    close();
  };
  const upload = async (f: File) => {
    setBusy(true);
    try {
      pick(await api.upload<{ id: number; url: string }>('media', f));
    } catch (e) {
      useStore.getState().toast(e instanceof Error ? e.message : String(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal wide title={t('Media library')} onClose={close}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: 6 }}>
        <input className="ve-input" placeholder={t('Search…')} value={q} onChange={(e) => setQ(e.target.value)} />
        <input className="ve-input" placeholder={t('…or paste an image URL to import')} value={url} onChange={(e) => setUrl(e.target.value)} onKeyDown={async (e) => { if (e.key === 'Enter' && url) { setBusy(true); try { pick(await api.post<{ id: number; url: string }>('media', { url })); } catch (er) { useStore.getState().toast(String(er), 'error'); } finally { setBusy(false); } } }} />
        <button className="ve-btn primary" disabled={busy} onClick={() => file.current?.click()}>
          <Upload size={14} /> {busy ? t('Uploading…') : t('Upload')}
        </button>
        <input ref={file} type="file" accept="image/*,video/*,application/pdf" hidden onChange={(e) => e.target.files?.[0] && void upload(e.target.files[0])} />
      </div>
      <div
        style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(120px, 1fr))', gap: 8 }}
        onDragOver={(e) => e.preventDefault()}
        onDrop={(e) => {
          e.preventDefault();
          const f = e.dataTransfer.files[0];
          if (f) void upload(f);
        }}
      >
        {items.map((m) => (
          <button key={m.id} className="ve-card" style={{ padding: 4 }} onClick={() => pick(m)} title={m.title}>
            <span style={{ aspectRatio: '1', background: `#0b0d12 url("${m.thumb}") center/cover`, borderRadius: 6 }} />
            <small style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.title}</small>
          </button>
        ))}
        {items.length === 0 && <div className="ve-faint">{t('No media yet — drop a file here.')}</div>}
      </div>
    </Modal>
  );
};

const ShortcutsModal = () => (
  <Modal title={t('Keyboard shortcuts')} onClose={close}>
    {[
      ['Ctrl+S', t('Publish')],
      ['Ctrl+Z / Ctrl+Shift+Z', t('Undo / redo')],
      ['Delete', t('Delete the selected element')],
      ['Ctrl+D', t('Duplicate')],
      ['Esc', t('Select parent')],
      ['Alt+↑ / Alt+↓', t('Move up / down')],
      [t('Double click'), t('Edit text on the page')],
      ['Ctrl+C / Ctrl+V', t('Copy / paste element')],
      ['1 / 2 / 3', t('Desktop / tablet / mobile')],
      ['P', t('Design ↔ interact mode')],
    ].map(([k, v]) => (
      <div key={k} style={{ display: 'flex', justifyContent: 'space-between' }}>
        <span>{v}</span>
        <span className="ve-kbd">{k}</span>
      </div>
    ))}
  </Modal>
);
