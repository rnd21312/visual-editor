import { lazy, Suspense, useState } from 'react';
import { Square, Database, LayoutPanelTop, Component as ComponentIcon, Sparkles, Shapes } from 'lucide-react';
import { useStore, newId } from '../store';
import { PRESETS, KIT_CSS, KIT_MARK, ISLANDS, type Preset } from '../presets';
import { insertHtml, insertComponent } from '../actions';
import { compileJsx } from '../jsx';
import { Seg } from './ui';
import { t } from '../i18n';

const IconPicker = lazy(() => import('./IconPicker'));

type Tab = 'elements' | 'dynamic' | 'sections' | 'kit' | 'components' | 'icons';

export const ensureKit = (): void => {
  const s = useStore.getState();
  if (!s.doc.css.includes(KIT_MARK)) s.setDoc((d) => void (d.css = KIT_CSS + (d.css ? '\n' + d.css : '')));
};

export const AddPanel = () => {
  const [tab, setTab] = useState<Tab>('sections');
  const [pos, setPos] = useState<'after' | 'append' | 'before'>('after');
  const cfg = useStore((s) => s.cfg);
  const doc = useStore((s) => s.doc);
  const selected = useStore((s) => s.selected);
  const [q, setQ] = useState('');

  const add = (p: Preset) => {
    if (p.needsKit) ensureKit();
    insertHtml(p.html, selected ? pos : 'after', t(p.name));
  };

  const addIsland = async (i: (typeof ISLANDS)[number]) => {
    const s = useStore.getState();
    if (!cfg.user.trusted) {
      s.toast(t('React components run code: only administrators can add them.'), 'error');
      return;
    }
    ensureKit();
    let comp = s.doc.components.find((c) => c.name === 'UI ' + i.name);
    if (!comp) {
      const { code, imports } = await compileJsx(i.jsx, cfg.react);
      const id = newId();
      s.setDoc((d) => {
        d.components.push({ id, name: 'UI ' + i.name, kind: 'react', category: 'UI kit', html: i.html, css: i.css, jsx: i.jsx, compiled: code, imports, props: [] });
      });
      comp = useStore.getState().doc.components.find((c) => c.id === id);
    }
    if (comp) insertComponent(comp.id);
  };

  const list = PRESETS.filter((p) => p.group === tab && (!p.woo || cfg.woocommerce) && (!q || t(p.name).toLowerCase().includes(q.toLowerCase()) || p.name.toLowerCase().includes(q.toLowerCase())));

  return (
    <div style={{ display: 'flex', flexDirection: 'column', minHeight: 0, height: '100%' }}>
      <div className="ve-tabs">
        {(
          [
            ['sections', <LayoutPanelTop size={13} key="i" />, t('Sections')],
            ['elements', <Square size={13} key="i" />, t('Elements')],
            ['dynamic', <Database size={13} key="i" />, t('Dynamic')],
            ['kit', <Sparkles size={13} key="i" />, t('UI kit')],
            ['components', <ComponentIcon size={13} key="i" />, t('Components')],
            ['icons', <Shapes size={13} key="i" />, t('Icons')],
          ] as const
        ).map(([k, icon, label]) => (
          <button key={k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>
            {icon} {label}
          </button>
        ))}
      </div>
      <div className="ve-pad" style={{ display: 'grid', gap: 6, borderBottom: '1px solid var(--ve-line)' }}>
        <input className="ve-input" placeholder={t('Search blocks…')} value={q} onChange={(e) => setQ(e.target.value)} />
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span className="ve-faint">{selected ? t('Insert') : t('Insert at the end of the page')}</span>
          {selected && (
            <Seg
              value={pos}
              onChange={setPos}
              options={[
                { value: 'before', label: t('before') },
                { value: 'after', label: t('after') },
                { value: 'append', label: t('inside') },
              ]}
            />
          )}
        </div>
      </div>
      <div className="ve-scroll ve-pad">
        {tab === 'components' ? (
          <div className="ve-list">
            {doc.components.length === 0 && <div className="ve-faint">{t('No components yet. Right click an element → Save as component, or create one in the Components panel.')}</div>}
            {doc.components.map((c) => (
              <div key={c.id} className="ve-item" onClick={() => insertComponent(c.id)}>
                <span className="ve-thumb">
                  <ComponentIcon size={14} />
                </span>
                <span className="t">{c.name}</span>
                <span className="meta">{c.kind}</span>
              </div>
            ))}
          </div>
        ) : tab === 'icons' ? (
          <Suspense fallback={<div className="ve-loading" />}>
            <IconPicker onPick={(svg) => insertHtml(svg, selected ? pos : 'after', t('Icon'))} />
          </Suspense>
        ) : (
          <>
            <div className="ve-cards">
              {list.map((p) => (
                <button key={p.id} className={'ve-card' + (tab === 'sections' ? ' wide' : '')} onClick={() => add(p)} title={p.hint}>
                  <span className="ic">{tab === 'dynamic' ? <Database size={15} /> : tab === 'sections' ? <LayoutPanelTop size={15} /> : <Square size={15} />}</span>
                  <span style={{ display: 'grid', minWidth: 0 }}>
                    <b>{t(p.name)}</b>
                    {p.hint && <small>{t(p.hint)}</small>}
                  </span>
                </button>
              ))}
            </div>
            {tab === 'kit' && (
              <>
                <div className="ve-h" style={{ marginTop: 14 }}>
                  {t('Interactive (React + Radix, shadcn-style)')}
                </div>
                <div className="ve-cards">
                  {ISLANDS.map((i) => (
                    <button key={i.name} className="ve-card" onClick={() => void addIsland(i)}>
                      <span className="ic">
                        <Sparkles size={15} />
                      </span>
                      <b>{i.name}</b>
                      <small>React island</small>
                    </button>
                  ))}
                </div>
              </>
            )}
            {tab === 'dynamic' && <p className="ve-faint" style={{ marginTop: 12 }}>{t('Dynamic blocks show live WordPress data. They work inside builder pages and templates.')}</p>}
          </>
        )}
      </div>
    </div>
  );
};
