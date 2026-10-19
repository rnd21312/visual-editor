import { useState } from 'react';
import { Plus, Component as ComponentIcon, Trash2, PenLine, X } from 'lucide-react';
import { useStore, newId, flushWorking } from '../store';
import { insertComponent } from '../actions';
import { invalidateComponents } from '../frame';
import { compileJsx } from '../jsx';
import { ensureIds } from '../../shared/source';
import type { Component, PropDef } from '../../shared/types';
import { Modal, Row, Field, Select, Seg, Code } from './ui';
import { t } from '../i18n';

export const ComponentsPanel = () => {
  const doc = useStore((s) => s.doc);
  const [q, setQ] = useState('');
  const list = doc.components.filter((c) => !q || c.name.toLowerCase().includes(q.toLowerCase()));
  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%', minHeight: 0 }}>
      <div className="ve-pad" style={{ display: 'grid', gap: 6, borderBottom: '1px solid var(--ve-line)' }}>
        <button className="ve-btn primary block" onClick={() => useStore.setState({ modal: { type: 'component' } })}>
          <Plus size={14} /> {t('New component')}
        </button>
        <input className="ve-input" placeholder={t('Search components…')} value={q} onChange={(e) => setQ(e.target.value)} />
        <p className="ve-faint" style={{ margin: 0 }}>{t('Components are reusable blocks: change one and every page that uses it updates — like React components, rendered on the server.')}</p>
      </div>
      <div className="ve-scroll ve-pad">
        <div className="ve-list">
          {list.map((c) => (
            <div key={c.id} className="ve-item">
              <span className="ve-thumb">
                <ComponentIcon size={14} />
              </span>
              <span style={{ display: 'grid', minWidth: 0 }} onClick={() => insertComponent(c.id)} title={t('Insert')}>
                <span className="t">{c.name}</span>
                <span className="ve-faint">
                  {c.kind}
                  {c.props.length ? ' · ' + c.props.length + ' props' : ''}
                  {c.category ? ' · ' + c.category : ''}
                </span>
              </span>
              <span style={{ marginInlineStart: 'auto', display: 'flex' }}>
                <button className="ve-icon-btn" title={t('Insert')} onClick={() => insertComponent(c.id)}>
                  <Plus size={14} />
                </button>
                <button className="ve-icon-btn" title={t('Edit')} onClick={() => useStore.setState({ modal: { type: 'component', id: c.id } })}>
                  <PenLine size={14} />
                </button>
              </span>
            </div>
          ))}
          {list.length === 0 && <div className="ve-faint">{t('No components yet.')}</div>}
        </div>
      </div>
    </div>
  );
};

const blank = (): Component => ({ id: newId(), name: '', kind: 'html', category: '', html: '<div class="my-card" data-sid="">\n  <h3>{{ props.title }}</h3>\n  <sve-slot></sve-slot>\n</div>', css: '.my-card { padding: 24px; border-radius: var(--radius, 14px); }', props: [{ name: 'title', label: 'Title', type: 'text', default: 'Hello', options: [] }] });

const REACT_TEMPLATE = `import { useState } from "react";

export default function Widget({ title = "Hello" }) {
  const [open, setOpen] = useState(false);
  return (
    <div>
      <button className="sve-btn" onClick={() => setOpen(!open)}>{title}</button>
      {open && <p>Any npm package works: import from "framer-motion", "recharts", "@radix-ui/…"</p>}
    </div>
  );
}`;

export const ComponentEditor = ({ id }: { id?: string }) => {
  const s = useStore.getState();
  const existing = id ? s.doc.components.find((c) => c.id === id) : undefined;
  const [c, setC] = useState<Component>(() => (existing ? JSON.parse(JSON.stringify(existing)) : blank()));
  const [tab, setTab] = useState<'markup' | 'css' | 'js' | 'props'>('markup');
  const [error, setError] = useState('');
  const close = () => useStore.setState({ modal: null });
  const trusted = s.cfg.user.trusted;

  const save = async () => {
    if (!c.name.trim()) return setError(t('Give the component a name.'));
    if (s.doc.components.some((x) => x.id !== c.id && x.name.toLowerCase() === c.name.trim().toLowerCase())) return setError(t('Another component already has this name.'));
    const next = { ...c, name: c.name.trim(), html: ensureIds(c.html.replace(/\sdata-sid=""/g, '')) };
    if (next.kind === 'react') {
      if (!trusted) return setError(t('React components run code: only administrators can add them.'));
      try {
        const out = await compileJsx(next.jsx ?? '', s.cfg.react);
        next.compiled = out.code;
        next.imports = out.imports;
      } catch (e) {
        return setError(e instanceof Error ? e.message : String(e));
      }
    }
    s.setDoc((d) => {
      const i = d.components.findIndex((x) => x.id === next.id);
      if (i >= 0) d.components[i] = next;
      else d.components.push(next);
    });
    invalidateComponents();
    close();
    // Components render on the server: refresh the preview with the new version.
    await flushWorking();
    useStore.getState().reload();
  };

  const remove = () => {
    if (!existing || !window.confirm(t('Delete component “{name}”? Pages using it will show nothing in its place.', { name: existing.name }))) return;
    s.setDoc((d) => {
      d.components = d.components.filter((x) => x.id !== existing.id);
      d.ops = d.ops.filter((o) => o.comp !== existing.id);
    });
    close();
  };

  const setProp = (i: number, p: Partial<PropDef>) => setC({ ...c, props: c.props.map((x, j) => (j === i ? { ...x, ...p } : x)) });

  return (
    <Modal
      wide
      title={existing ? t('Edit component') : t('New component')}
      onClose={close}
      footer={
        <>
          {existing && (
            <button className="ve-btn danger" style={{ marginInlineEnd: 'auto' }} onClick={remove}>
              <Trash2 size={13} /> {t('Delete')}
            </button>
          )}
          {error && <span style={{ color: '#fca5a5' }}>{error}</span>}
          <button className="ve-btn" onClick={close}>
            {t('Cancel')}
          </button>
          <button className="ve-btn primary" onClick={() => void save()}>
            {t('Save component')}
          </button>
        </>
      }
    >
      <div className="ve-grid3">
        <Field value={c.name} onChange={(name) => setC({ ...c, name })} placeholder={t('Name (used as ref)')} />
        <Select
          value={c.kind}
          onChange={(kind) => setC({ ...c, kind: kind as Component['kind'], jsx: kind === 'react' && !c.jsx ? REACT_TEMPLATE : c.jsx })}
          options={[
            { value: 'html', label: t('HTML (server-rendered)') },
            { value: 'section', label: t('Section preset') },
            { value: 'react', label: t('React island') + (trusted ? '' : ' 🔒') },
            { value: 'embed', label: t('Embed (scripts)') + (trusted ? '' : ' 🔒') },
            { value: 'jsonld', label: 'JSON-LD' },
          ]}
        />
        <Field value={c.category} onChange={(category) => setC({ ...c, category })} placeholder={t('Category')} />
      </div>
      <Seg
        value={tab}
        onChange={setTab}
        options={[
          { value: 'markup', label: c.kind === 'react' ? 'JSX' : t('Markup') },
          { value: 'css', label: 'CSS' },
          ...(c.kind !== 'react' && trusted ? [{ value: 'js' as const, label: 'JS' }] : []),
          { value: 'props', label: t('Props') + ` (${c.props.length})` },
        ]}
      />
      {tab === 'markup' &&
        (c.kind === 'react' ? (
          <>
            <Code value={c.jsx ?? ''} onChange={(jsx) => setC({ ...c, jsx })} lang="jsx" height={360} />
            <div className="ve-faint">{t('Default export = the component. Props come from the instance. npm imports load from esm.sh with one shared React.')}</div>
            <Row label={t('Fallback HTML')}>
              <Code value={c.html} onChange={(html) => setC({ ...c, html })} lang="html" height={90} />
            </Row>
          </>
        ) : (
          <>
            <Code value={c.html} onChange={(html) => setC({ ...c, html })} lang="html" height={360} />
            <div className="ve-faint">{t('Use {{ props.name }} for props, <sve-slot></sve-slot> for the inner content, and any binding ({{ post.title }}, loops…).')}</div>
          </>
        ))}
      {tab === 'css' && <Code value={c.css} onChange={(css) => setC({ ...c, css })} lang="css" height={360} />}
      {tab === 'js' && (
        <>
          <Code value={c.js ?? ''} onChange={(js) => setC({ ...c, js })} lang="js" height={300} />
          <div className="ve-faint">{t('Runs once per instance; `root` is the instance element.')}</div>
        </>
      )}
      {tab === 'props' && (
        <div style={{ display: 'grid', gap: 6 }}>
          {c.props.map((p, i) => (
            <div key={i} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 120px 1fr 28px', gap: 6 }}>
              <input className="ve-input code" value={p.name} placeholder="name" onChange={(e) => setProp(i, { name: e.target.value.replace(/[^\w]/g, '') })} />
              <input className="ve-input" value={p.label} placeholder={t('Label')} onChange={(e) => setProp(i, { label: e.target.value })} />
              <select className="ve-select" value={p.type} onChange={(e) => setProp(i, { type: e.target.value as PropDef['type'] })}>
                {['text', 'html', 'url', 'image', 'number', 'bool', 'color', 'select', 'json', 'list'].map((x) => (
                  <option key={x}>{x}</option>
                ))}
              </select>
              <input className="ve-input" value={typeof p.default === 'string' ? p.default : JSON.stringify(p.default ?? '')} placeholder={t('Default')} onChange={(e) => setProp(i, { default: e.target.value })} />
              <button className="ve-icon-btn" onClick={() => setC({ ...c, props: c.props.filter((_, j) => j !== i) })}>
                <X size={13} />
              </button>
            </div>
          ))}
          <button className="ve-btn small" onClick={() => setC({ ...c, props: [...c.props, { name: 'prop' + (c.props.length + 1), label: '', type: 'text', default: '', options: [] }] })}>
            <Plus size={13} /> {t('Add prop')}
          </button>
        </div>
      )}
    </Modal>
  );
};
