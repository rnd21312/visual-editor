import { useEffect, useMemo, useState } from 'react';
import { Paintbrush, Type, Sparkles, SlidersHorizontal, Image as ImageIcon, Trash2, Play, Boxes, Link2 } from 'lucide-react';
import { useStore } from '../store';
import { StylePanel, STATES, useStyles } from './StylePanel';
import { Section, Row, Seg, Field, Area, Select, Code } from './ui';
import { setAttribute, setContent, sourceOf, changeTag, saveSelectionAsComponent, removeSelected } from '../actions';
import { innerOf, openTagOf, getAttrs, outerOf } from '../../shared/source';
import { classSelector } from '../../shared/selector';
import { previewMotion } from '../frame';
import type { Motion, Scope } from '../../shared/types';
import { t } from '../i18n';

const ownerLabel = (kind: string) => ({ theme: t('Theme markup'), post: t('Builder layout'), component: t('Component'), op: t('Inserted element') })[kind] ?? kind;

export const Inspector = () => {
  const selected = useStore((s) => s.selected);
  const right = useStore((s) => s.right);
  const scope = useStore((s) => s.scope);
  const state = useStore((s) => s.state);
  const device = useStore((s) => s.device);
  const ctx = useStore((s) => s.ctx);

  if (!selected) {
    return (
      <div className="ve-empty">
        <p>{t('Click any element on the page to edit it.')}</p>
        <p className="ve-faint">{t('Double click text to type. Right click for more. Drag the handle to move.')}</p>
      </div>
    );
  }

  return (
    <>
      <div className="ve-pad" style={{ borderBottom: '1px solid var(--ve-line)', display: 'grid', gap: 8 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, minWidth: 0 }}>
          <b style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{selected.label}</b>
          <span className={'ve-chip' + (selected.owner.kind === 'theme' ? '' : ' ai')}>{ownerLabel(selected.owner.kind)}</span>
        </div>
        <Seg<Scope>
          value={scope}
          onChange={(v) => useStore.setState({ scope: v })}
          options={[
            { value: 'page', label: t('This page'), title: ctx?.page },
            { value: 'template', label: t('Template'), title: ctx?.template.label },
            { value: 'site', label: t('Whole site') },
          ]}
        />
        <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
          <select className="ve-select" style={{ width: 'auto' }} value={state} onChange={(e) => useStore.setState({ state: e.target.value as typeof state })}>
            {STATES.map((s) => (
              <option key={s.value} value={s.value}>
                {t(s.label)}
              </option>
            ))}
          </select>
          <span className="ve-faint">
            {device === 'desktop' ? t('All screen sizes') : device === 'tablet' ? t('Tablet and smaller') : t('Mobile only')}
            {scope === 'template' && ctx ? ' · ' + ctx.template.label : ''}
          </span>
        </div>
      </div>
      <div className="ve-tabs">
        {(
          [
            ['style', <Paintbrush size={13} key="i" />, t('Style')],
            ['content', <Type size={13} key="i" />, t('Content')],
            ['motion', <Sparkles size={13} key="i" />, t('Motion')],
            ['advanced', <SlidersHorizontal size={13} key="i" />, t('Advanced')],
          ] as const
        ).map(([k, icon, label]) => (
          <button key={k} className={right === k ? 'on' : ''} onClick={() => useStore.setState({ right: k })}>
            {icon} {label}
          </button>
        ))}
      </div>
      <div className="ve-scroll">
        {right === 'style' && <StylePanel />}
        {right === 'content' && <ContentPanel />}
        {right === 'motion' && <MotionPanel />}
        {right === 'advanced' && <AdvancedPanel />}
      </div>
    </>
  );
};

/* ------------------------------------------------------------------ content */

const BINDINGS = ['{{ post.title }}', '{{ post.excerpt }}', '{{ post.date }}', '{{ post.author.name }}', '{{ post.url }}', '{{ post.image.large }}', '{{{ post.content }}}', '{{ site.name }}', '{{ site.description }}', '{{ site.year }}', '{{ product.price_html }}', '{{ product.add_to_cart }}', '{{ term.name }}', '{{ archive.title }}', '{{ user.name }}', '{{ cart.count }}', '{{ props.title }}'];

const ContentPanel = () => {
  const selected = useStore((s) => s.selected)!;
  const sources = useStore((s) => s.sources);
  const doc = useStore((s) => s.doc);
  const el = selected.el;
  const builder = selected.owner.kind !== 'theme' && selected.sid;
  const source = builder ? sourceOf(selected.owner) : null;
  const inner = source && selected.sid ? innerOf(source, selected.sid) : null;
  const open = source && selected.sid ? openTagOf(source, selected.sid) : null;
  const attrs = useMemo(() => (open ? getAttrs(open) : Object.fromEntries(Array.from(el.attributes).map((a) => [a.name, a.value]))), [open, el, sources, doc]);
  const [code, setCode] = useState(inner ?? '');
  useEffect(() => setCode(inner ?? ''), [inner, selected.sid]);
  const tag = el.localName;
  const textOnly = el.children.length === 0;
  const instance = el.getAttribute('data-sve-instance');
  const loop = el.getAttribute('data-sve-tag') === 'loop';

  const attr = (name: string, label: string, placeholder = '') => (
    <Row label={label} set={!!attrs[name]}>
      <Field value={attrs[name] ?? ''} onChange={(v) => setAttribute(name, v === '' ? null : v)} placeholder={placeholder} mono={name !== 'alt' && name !== 'title'} />
    </Row>
  );

  return (
    <div>
      {instance && <ComponentProps sid={selected.sid!} owner={selected.owner} attrs={attrs} />}
      {loop && <LoopEditor attrs={attrs} />}
      {!instance && !loop && (
        <Section title={t('Text & markup')}>
          {builder && inner !== null ? (
            <>
              {!/[<{]/.test(inner) ? (
                <Area value={inner} onChange={(v) => setContent('text', v)} rows={3} />
              ) : (
                <>
                  <Code value={code} onChange={setCode} lang="html" height={180} />
                  <div style={{ display: 'flex', gap: 6 }}>
                    <button className="ve-btn small primary" onClick={() => setContent('html', code)}>
                      {t('Apply')}
                    </button>
                    <span className="ve-faint">{t('Bindings like {{ post.title }} stay live.')}</span>
                  </div>
                </>
              )}
              <Select
                value=""
                onChange={(v) => v && (/[<{]/.test(inner) ? setCode(code + v) : setContent('html', inner + v))}
                placeholder={t('+ Insert dynamic data…')}
                options={BINDINGS}
              />
            </>
          ) : textOnly ? (
            <Area value={el.textContent ?? ''} onChange={(v) => setContent('text', v)} rows={3} />
          ) : (
            <>
              <Code value={code || el.innerHTML} onChange={setCode} lang="html" height={180} />
              <button className="ve-btn small primary" onClick={() => setContent('html', code || el.innerHTML)}>
                {t('Apply')}
              </button>
            </>
          )}
        </Section>
      )}
      {tag === 'a' && (
        <Section title={t('Link')}>
          {attr('href', t('URL'), 'https://… / #section')}
          <Row label={t('New tab')}>
            <input type="checkbox" checked={attrs.target === '_blank'} onChange={(e) => {
              setAttribute('target', e.target.checked ? '_blank' : null);
              setAttribute('rel', e.target.checked ? 'noopener' : null);
            }} />
          </Row>
          <LinkPicker onPick={(url) => setAttribute('href', url)} />
        </Section>
      )}
      {(tag === 'img' || tag === 'video' || tag === 'source' || tag === 'iframe') && (
        <Section title={tag === 'img' ? t('Image') : t('Media')}>
          {attr('src', t('Source'))}
          {tag === 'img' && attr('alt', t('Alt text'), t('Describe the image'))}
          <button className="ve-btn small" onClick={() => useStore.setState({ modal: { type: 'media', onPick: (m) => { setAttribute('src', m.url); setAttribute('srcset', null); if (tag === 'img' && m.alt) setAttribute('alt', m.alt); } } })}>
            <ImageIcon size={13} /> {t('Choose from media library')}
          </button>
          {tag === 'img' && (
            <Row label={t('Loading')}>
              <Seg value={attrs.loading || 'auto'} onChange={(v) => setAttribute('loading', v === 'auto' ? null : v)} options={[{ value: 'auto' }, { value: 'lazy' }, { value: 'eager' }]} />
            </Row>
          )}
        </Section>
      )}
      <Section title={t('Attributes')} open={false}>
        {attr('id', 'id')}
        {attr('class', 'class')}
        {attr('title', t('Tooltip'))}
        {attr('aria-label', 'aria-label')}
        {Object.entries(attrs)
          .filter(([k]) => k.startsWith('data-') && !['data-sid', 'data-ve-id', 'data-ve-ops', 'data-sve-instance', 'data-sve-tag'].includes(k))
          .map(([k]) => attr(k, k))}
        <AddAttribute />
      </Section>
    </div>
  );
};

const AddAttribute = () => {
  const [name, setName] = useState('');
  return (
    <div className="ve-grid2">
      <input className="ve-input code" placeholder="data-…" value={name} onChange={(e) => setName(e.target.value)} />
      <input
        className="ve-input code"
        placeholder={t('value + Enter')}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && name && !/^on/i.test(name)) {
            setAttribute(name, (e.target as HTMLInputElement).value);
            setName('');
            (e.target as HTMLInputElement).value = '';
          }
        }}
      />
    </div>
  );
};

const LinkPicker = ({ onPick }: { onPick: (url: string) => void }) => {
  const api = useStore((s) => s.api);
  const [q, setQ] = useState('');
  const [items, setItems] = useState<{ id: number; title: string; url: string }[]>([]);
  useEffect(() => {
    if (!q) return setItems([]);
    const id = window.setTimeout(() => void api.get<{ items: { id: number; title: string; url: string }[] }>('content', { search: q, per_page: 8 }).then((r) => setItems(r.items)), 250);
    return () => window.clearTimeout(id);
  }, [q, api]);
  return (
    <div style={{ display: 'grid', gap: 4 }}>
      <input className="ve-input" placeholder={t('Link to a page of this site…')} value={q} onChange={(e) => setQ(e.target.value)} />
      {items.map((i) => (
        <div key={i.id} className="ve-item" onClick={() => { onPick(i.url); setQ(''); }}>
          <Link2 size={13} /> <span className="t">{i.title}</span>
        </div>
      ))}
    </div>
  );
};

/** Props of a component instance (<sve-component props='…'>). */
const ComponentProps = ({ sid, attrs, owner }: { sid: string; attrs: Record<string, string>; owner: import('../store').Owner }) => {
  const doc = useStore((s) => s.doc);
  const ref = attrs.ref ?? '';
  const comp = doc.components.find((c) => c.id === ref || c.name.toLowerCase() === ref.toLowerCase());
  let props: Record<string, unknown> = {};
  try {
    props = JSON.parse(attrs.props || '{}');
  } catch {
    /* invalid JSON in source */
  }
  const set = (name: string, value: unknown) => {
    const next = { ...props, [name]: value };
    if (value === '' || value === undefined) delete next[name];
    if (owner.kind === 'post') useStore.getState().editSource(owner.id, 'set_attrs', sid, { attrs: { props: JSON.stringify(next) } });
    window.setTimeout(() => useStore.getState().reload(), 500);
  };
  if (!comp) return <div className="ve-pad ve-faint">{t('Component “{name}” not found.', { name: ref })}</div>;
  return (
    <Section title={t('Component: {name}', { name: comp.name })} right={<button className="ve-btn small" onClick={() => useStore.setState({ modal: { type: 'component', id: comp.id } })}>{t('Edit component')}</button>}>
      {comp.props.length === 0 && <div className="ve-faint">{t('This component has no props. Add some in the component editor.')}</div>}
      {comp.props.map((p) => (
        <Row key={p.name} label={p.label || p.name}>
          {p.type === 'bool' ? (
            <input type="checkbox" checked={!!(props[p.name] ?? p.default)} onChange={(e) => set(p.name, e.target.checked)} />
          ) : p.type === 'select' ? (
            <Select value={String(props[p.name] ?? p.default ?? '')} onChange={(v) => set(p.name, v)} options={p.options} />
          ) : p.type === 'image' ? (
            <div style={{ display: 'flex', gap: 4 }}>
              <Field value={String(props[p.name] ?? '')} onChange={(v) => set(p.name, v)} placeholder={String(p.default ?? '')} />
              <button className="ve-icon-btn" onClick={() => useStore.setState({ modal: { type: 'media', onPick: (m) => set(p.name, m.url) } })}>
                <ImageIcon size={14} />
              </button>
            </div>
          ) : p.type === 'html' || p.type === 'json' || p.type === 'list' ? (
            <Area value={typeof props[p.name] === 'string' ? (props[p.name] as string) : JSON.stringify(props[p.name] ?? p.default ?? '')} onChange={(v) => set(p.name, p.type === 'html' ? v : (() => { try { return JSON.parse(v); } catch { return v; } })())} rows={3} mono />
          ) : (
            <Field value={String(props[p.name] ?? '')} onChange={(v) => set(p.name, v)} placeholder={String(p.default ?? '')} />
          )}
        </Row>
      ))}
    </Section>
  );
};

/** Query of a <sve-loop>. */
const LoopEditor = ({ attrs }: { attrs: Record<string, string> }) => {
  const selected = useStore((s) => s.selected)!;
  const cfg = useStore((s) => s.cfg);
  let q: Record<string, unknown> = {};
  try {
    q = JSON.parse(attrs.query || '{}');
  } catch {
    /* keep empty */
  }
  const set = (k: string, v: unknown) => {
    const next = { ...q, [k]: v };
    if (v === '' || v === undefined) delete next[k];
    setAttribute('query', JSON.stringify(next));
    window.setTimeout(() => useStore.getState().reload(), 500);
  };
  return (
    <Section title={t('Loop (dynamic list)')}>
      <Row label={t('Content')}>
        <Select value={String(q.post_type ?? 'post')} onChange={(v) => set('post_type', v)} options={Object.values(cfg.types).map((ty) => ({ value: ty.name, label: ty.label }))} />
      </Row>
      <div className="ve-grid2">
        <Row label={t('Count')}>
          <Field value={String(q.posts_per_page ?? 6)} onChange={(v) => set('posts_per_page', Number(v) || 6)} />
        </Row>
        <Row label={t('Order')}>
          <Select value={String(q.orderby ?? 'date')} onChange={(v) => set('orderby', v)} options={['date', 'title', 'rand', 'menu_order', 'modified', 'price', 'popularity', 'rating']} />
        </Row>
      </div>
      <Row label={t('Category')}>
        <Field value={String(q.category ?? q.product_cat ?? '')} onChange={(v) => set(q.post_type === 'product' ? 'product_cat' : 'category', v)} placeholder="slug" />
      </Row>
      <div className="ve-grid2">
        <label className="ve-check"><input type="checkbox" checked={!!q.exclude_current} onChange={(e) => set('exclude_current', e.target.checked || undefined)} /> {t('Skip current')}</label>
        {q.post_type === 'product' && <label className="ve-check"><input type="checkbox" checked={!!q.on_sale} onChange={(e) => set('on_sale', e.target.checked || undefined)} /> {t('On sale')}</label>}
      </div>
      <div className="ve-faint">{t('Inside the loop use {{ item.title }}, {{ item.url }}, {{ item.image.medium }}…')} ({selected.sid})</div>
    </Section>
  );
};

/* ------------------------------------------------------------------ motion */

const PRESETS = ['fade', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom-in', 'zoom-out', 'flip-up', 'rotate-in', 'blur-in', 'slide-up', 'slide-left', 'slide-right', 'parallax', 'float', 'pulse', 'spin', 'stagger-children', 'reveal-text'];

const MotionPanel = () => {
  const selected = useStore((s) => s.selected)!;
  const updateEdit = useStore((s) => s.updateEdit);
  const { edit } = useStyles();
  const m = edit?.motion;
  const set = (patch: Partial<Motion>) =>
    updateEdit(selected.sel, (e) => {
      e.motion = { preset: 'fade-up', trigger: 'scroll', duration: 0.8, delay: 0, stagger: 0, distance: 40, repeat: 0, yoyo: false, once: true, ease: 'power3.out', start: 'top 85%', ...(e.motion ?? {}), ...patch } as Motion;
    }, undefined, selected.label);
  return (
    <div>
      <Section title={t('Animation')} hint="GSAP">
        <div className="ve-cards">
          {PRESETS.map((p) => (
            <button key={p} className={'ve-card' + (m?.preset === p ? ' on' : '')} style={m?.preset === p ? { borderColor: 'var(--ve-accent)' } : undefined} onClick={() => { set({ preset: p }); window.setTimeout(() => { const e = useStore.getState().doc.edits.find((x) => x.sel === selected.sel && x.motion); if (e?.motion) previewMotion(selected.sel, e.motion); }, 50); }}>
              <b>{p}</b>
            </button>
          ))}
        </div>
      </Section>
      {m && (
        <Section title={t('Timing')}>
          <Row label={t('Trigger')}>
            <Select value={m.trigger} onChange={(v) => set({ trigger: v as Motion['trigger'] })} options={[{ value: 'scroll', label: t('When scrolled into view') }, { value: 'load', label: t('On page load') }, { value: 'hover', label: t('On hover') }, { value: 'click', label: t('On click') }, { value: 'scrub', label: t('Follows the scroll') }]} />
          </Row>
          <div className="ve-grid2">
            <Row label={t('Duration')}><Field value={String(m.duration)} onChange={(v) => set({ duration: Number(v) || 0 })} /></Row>
            <Row label={t('Delay')}><Field value={String(m.delay)} onChange={(v) => set({ delay: Number(v) || 0 })} /></Row>
            <Row label={t('Distance')}><Field value={String(m.distance)} onChange={(v) => set({ distance: Number(v) || 0 })} /></Row>
            <Row label={t('Stagger')}><Field value={String(m.stagger)} onChange={(v) => set({ stagger: Number(v) || 0 })} /></Row>
          </div>
          <Row label={t('Easing')}>
            <Select value={m.ease} onChange={(v) => set({ ease: v })} options={['power1.out', 'power2.out', 'power3.out', 'power4.out', 'back.out(1.7)', 'elastic.out(1,0.4)', 'expo.out', 'sine.inOut', 'none']} />
          </Row>
          <div className="ve-grid2">
            <label className="ve-check"><input type="checkbox" checked={m.once} onChange={(e) => set({ once: e.target.checked })} /> {t('Play once')}</label>
            <label className="ve-check"><input type="checkbox" checked={m.repeat === -1} onChange={(e) => set({ repeat: e.target.checked ? -1 : 0, yoyo: e.target.checked })} /> {t('Loop')}</label>
          </div>
          <div style={{ display: 'flex', gap: 6 }}>
            <button className="ve-btn small" onClick={() => previewMotion(selected.sel, m)}>
              <Play size={13} /> {t('Preview')}
            </button>
            <button className="ve-btn small danger" onClick={() => updateEdit(selected.sel, (e) => { delete e.motion; })}>
              <Trash2 size={13} /> {t('Remove')}
            </button>
          </div>
        </Section>
      )}
    </div>
  );
};

/* ------------------------------------------------------------------ advanced */

const AdvancedPanel = () => {
  const selected = useStore((s) => s.selected)!;
  const doc = useStore((s) => s.doc);
  const updateEdit = useStore((s) => s.updateEdit);
  const removeEdit = useStore((s) => s.removeEdit);
  const { edit } = useStyles();
  const similar = classSelector(selected.el);
  const related = doc.edits.filter((e) => e.sel === selected.sel);
  const source = selected.owner.kind !== 'theme' && selected.sid ? sourceOf(selected.owner) : null;
  const outer = source && selected.sid ? outerOf(source, selected.sid) : null;
  const [code, setCode] = useState(outer ?? '');
  useEffect(() => setCode(outer ?? ''), [outer]);

  return (
    <div>
      <Section title={t('Target')}>
        <Field value={selected.sel} onChange={(v) => useStore.setState({ selected: { ...selected, sel: v } })} mono />
        {similar && similar !== selected.sel && (
          <button className="ve-btn small" onClick={() => useStore.setState({ selected: { ...selected, sel: similar } })}>
            <Boxes size={13} /> {t('Style all similar ({sel})', { sel: similar })}
          </button>
        )}
        <div className="ve-faint">{t('Changes apply to every element matching this selector.')}</div>
      </Section>
      <Section title={t('Custom CSS')} hint={t('"selector" = this element')}>
        <Code value={edit?.css ?? ''} onChange={(v) => updateEdit(selected.sel, (e) => { e.css = v; }, undefined, selected.label)} lang="css" height={150} />
      </Section>
      <Section title={t('Element')}>
        <Row label={t('Tag')}>
          <Select value={selected.el.localName} onChange={changeTag} options={['div', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'main', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'button', 'ul', 'ol', 'li', 'figure', 'blockquote']} />
        </Row>
        <button className="ve-btn small" onClick={() => { const n = window.prompt(t('Component name'), selected.label); if (n) saveSelectionAsComponent(n); }}>
          {t('Save as component…')}
        </button>
        <button className="ve-btn small danger" onClick={removeSelected}>
          <Trash2 size={13} /> {t('Delete element')}
        </button>
      </Section>
      {outer !== null && (
        <Section title={t('Layout source')} open={false}>
          <Code value={code} onChange={setCode} lang="html" height={220} />
          <button className="ve-btn small primary" onClick={() => { if (selected.sid && selected.owner.kind === 'post') { useStore.getState().editSource(selected.owner.id, 'replace', selected.sid, { html: code }); window.setTimeout(() => useStore.getState().reload(), 500); } }}>
            {t('Apply')}
          </button>
        </Section>
      )}
      <Section title={t('Changes on this element')} hint={String(related.length)}>
        {related.length === 0 && <div className="ve-faint">{t('None yet.')}</div>}
        {related.map((e) => (
          <div key={e.id} className="ve-item">
            <span className="t">
              {e.scope === 'page' ? t('Page') + ' ' + e.page : e.scope === 'template' ? t('Template') + ' ' + e.tpl : t('Whole site')}
            </span>
            <span className="meta">{[Object.keys(e.styles).length && 'style', e.text !== undefined && 'text', e.html !== undefined && 'html', e.motion && 'motion', e.hide && 'hide'].filter(Boolean).join(', ')}</span>
            <button className="ve-icon-btn" title={t('Remove this change')} onClick={() => removeEdit(e.id)}>
              <Trash2 size={13} />
            </button>
          </div>
        ))}
        <div className="ve-faint">{t('Owner')}: {ownerLabel(selected.owner.kind)} {selected.sid && `· ${selected.sid}`}</div>
      </Section>
    </div>
  );
};
