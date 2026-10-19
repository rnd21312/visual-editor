import { useMemo, useState } from 'react';
import { AlignLeft, AlignCenter, AlignRight, AlignJustify, Plus, X, Eye, EyeOff, Image as ImageIcon, ArrowRight, ArrowDown, ArrowLeft, ArrowUp } from 'lucide-react';
import { useStore, deviceOf } from '../store';
import type { Edit, StateName } from '../../shared/types';
import { Section, Row, Seg, Field, ColorInput, UnitInput, Select } from './ui';
import { t } from '../i18n';

export const CSS_PROPS = 'align-content align-items align-self animation aspect-ratio backdrop-filter background background-attachment background-blend-mode background-clip background-color background-image background-origin background-position background-repeat background-size border border-bottom border-collapse border-color border-image border-left border-radius border-right border-spacing border-style border-top border-width bottom box-shadow box-sizing caret-color clip-path color column-count column-gap columns content cursor direction display filter flex flex-basis flex-direction flex-flow flex-grow flex-shrink flex-wrap float font font-family font-feature-settings font-size font-style font-variant font-weight gap grid grid-area grid-auto-columns grid-auto-flow grid-auto-rows grid-column grid-row grid-template grid-template-areas grid-template-columns grid-template-rows height hyphens inset isolation justify-content justify-items justify-self left letter-spacing line-clamp line-height list-style margin margin-block margin-bottom margin-inline margin-left margin-right margin-top mask max-height max-width min-height min-width mix-blend-mode object-fit object-position opacity order outline outline-offset overflow overflow-x overflow-y padding padding-block padding-bottom padding-inline padding-left padding-right padding-top place-content place-items pointer-events position resize right rotate row-gap scale scroll-behavior scroll-margin-top scroll-snap-align scroll-snap-type text-align text-decoration text-indent text-overflow text-shadow text-transform text-wrap top transform transform-origin transition translate user-select vertical-align visibility white-space width will-change word-break writing-mode z-index -webkit-line-clamp -webkit-box-orient'.split(' ');

/** Reads and writes the selected element's styles for the current device and state. */
export const useStyles = () => {
  const selected = useStore((s) => s.selected);
  const doc = useStore((s) => s.doc);
  const device = deviceOf(useStore((s) => s.device));
  const state = useStore((s) => s.state);
  const scope = useStore((s) => s.scope);
  const ctx = useStore((s) => s.ctx);
  const updateEdit = useStore((s) => s.updateEdit);

  const edit: Edit | undefined = useMemo(() => {
    if (!selected) return undefined;
    const page = ctx?.page ?? '/';
    const tpl = ctx?.template.key ?? '';
    return doc.edits.find((e) => e.sel === selected.sel && e.scope === scope && (scope !== 'page' || e.page === page) && (scope !== 'template' || e.tpl === tpl));
  }, [doc, selected, scope, ctx]);

  const bag: Record<string, string> = (state === 'normal' ? edit?.styles?.[device] : (edit?.states as Record<string, Record<string, Record<string, string>>> | undefined)?.[state]?.[device]) ?? {};

  const computed = useMemo(() => {
    if (!selected) return null;
    try {
      return selected.el.ownerDocument.defaultView?.getComputedStyle(selected.el, state === 'before' || state === 'after' ? '::' + state : null) ?? null;
    } catch {
      return null;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected, state, doc]);

  const set = (prop: string, value: string) => {
    if (!selected) return;
    updateEdit(
      selected.sel,
      (e) => {
        if (state === 'normal') {
          const b = { ...(e.styles[device] ?? {}) };
          if (value.trim() === '') delete b[prop];
          else b[prop] = value;
          e.styles = { ...e.styles, [device]: b };
        } else {
          const states = (e.states ?? {}) as Record<string, Record<string, Record<string, string>>>;
          const st = { ...(states[state] ?? {}) };
          const b = { ...(st[device] ?? {}) };
          if (value.trim() === '') delete b[prop];
          else b[prop] = value;
          st[device] = b;
          e.states = { ...states, [state]: st } as Edit['states'];
        }
      },
      undefined,
      selected.label,
    );
  };

  const get = (prop: string) => bag[prop] ?? '';
  const hint = (prop: string) => (computed ? computed.getPropertyValue(prop) : '');

  return { selected, edit, bag, get, set, hint, device, state };
};

const swatchesOf = (vars: Record<string, string>) =>
  Object.entries(vars)
    .filter(([k, v]) => /color|bg|brand|primary|accent|text|surface/i.test(k) || /^#|^rgb|^hsl|^oklch/i.test(v))
    .map(([name, value]) => ({ name, value }));

const BoxSides = ({ prefix, get, set, hint }: { prefix: 'margin' | 'padding'; get: (p: string) => string; set: (p: string, v: string) => void; hint: (p: string) => string }) => {
  const side = (s: 'top' | 'right' | 'bottom' | 'left', cls: string) => (
    <input
      className={cls}
      value={get(`${prefix}-${s}`)}
      placeholder={String(Math.round(parseFloat(hint(`${prefix}-${s}`)) || 0))}
      onChange={(e) => set(`${prefix}-${s}`, /^-?\d*\.?\d+$/.test(e.target.value) ? e.target.value + 'px' : e.target.value)}
      title={`${prefix}-${s}`}
    />
  );
  return (
    <>
      {side('top', 't')}
      {side('bottom', 'b')}
      {side('left', 'l')}
      {side('right', 'r')}
    </>
  );
};

const SHADOWS = {
  none: 'none',
  sm: '0 1px 2px rgb(0 0 0 / 0.08)',
  md: '0 4px 14px rgb(0 0 0 / 0.10)',
  lg: '0 12px 32px rgb(0 0 0 / 0.14)',
  xl: '0 24px 60px rgb(0 0 0 / 0.20)',
  glow: '0 0 0 4px rgb(99 102 241 / 0.25)',
};

const GRADIENTS = [
  'linear-gradient(135deg, #6366f1, #14b8a6)',
  'linear-gradient(135deg, #f59e0b, #ef4444)',
  'linear-gradient(180deg, #0f172a, #1e293b)',
  'linear-gradient(135deg, #fdf2f8, #eef2ff)',
  'radial-gradient(circle at 30% 20%, #a5b4fc, transparent 60%), #0f172a',
];

export const StylePanel = () => {
  const { selected, get, set, hint, bag, edit } = useStyles();
  const doc = useStore((s) => s.doc);
  const [newProp, setNewProp] = useState('');
  const swatches = swatchesOf(doc.tokens.vars);
  const fonts = doc.tokens.fonts.map((f) => ({ value: `"${f.family}", system-ui, sans-serif`, label: f.family }));
  if (!selected) return null;
  const display = get('display') || hint('display');
  const isFlex = display.includes('flex');
  const isGrid = display.includes('grid');
  const isImg = ['img', 'video', 'picture', 'iframe'].includes(selected.el.localName);

  return (
    <div>
      <Section title={t('Layout')}>
        <Row label={t('Display')} set={!!get('display')}>
          <Select value={get('display')} onChange={(v) => set('display', v)} placeholder={hint('display')} options={['block', 'flex', 'grid', 'inline-flex', 'inline-block', 'inline', 'contents', 'none']} />
        </Row>
        {isFlex && (
          <>
            <Row label={t('Direction')} set={!!get('flex-direction')}>
              <Seg
                value={get('flex-direction') || 'row'}
                onChange={(v) => set('flex-direction', v)}
                options={[
                  { value: 'row', label: <ArrowRight size={13} /> },
                  { value: 'column', label: <ArrowDown size={13} /> },
                  { value: 'row-reverse', label: <ArrowLeft size={13} /> },
                  { value: 'column-reverse', label: <ArrowUp size={13} /> },
                ]}
              />
            </Row>
            <Row label={t('Justify')} set={!!get('justify-content')}>
              <Select value={get('justify-content')} onChange={(v) => set('justify-content', v)} placeholder={hint('justify-content')} options={['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly']} />
            </Row>
            <Row label={t('Align')} set={!!get('align-items')}>
              <Select value={get('align-items')} onChange={(v) => set('align-items', v)} placeholder={hint('align-items')} options={['stretch', 'flex-start', 'center', 'flex-end', 'baseline']} />
            </Row>
            <Row label={t('Wrap')} set={!!get('flex-wrap')}>
              <Seg value={get('flex-wrap') || 'nowrap'} onChange={(v) => set('flex-wrap', v)} options={[{ value: 'nowrap', label: t('No') }, { value: 'wrap', label: t('Wrap') }]} />
            </Row>
          </>
        )}
        {isGrid && (
          <>
            <Row label={t('Columns')} set={!!get('grid-template-columns')}>
              <div style={{ display: 'grid', gap: 4 }}>
                <Seg
                  value={(/repeat\((\d+)/.exec(get('grid-template-columns'))?.[1] ?? '') as string}
                  onChange={(v) => set('grid-template-columns', `repeat(${v}, minmax(0, 1fr))`)}
                  options={['1', '2', '3', '4', '5', '6'].map((n) => ({ value: n }))}
                />
                <Field value={get('grid-template-columns')} onChange={(v) => set('grid-template-columns', v)} placeholder="repeat(auto-fit, minmax(240px, 1fr))" mono />
              </div>
            </Row>
            <Row label={t('Align')} set={!!get('align-items')}>
              <Select value={get('align-items')} onChange={(v) => set('align-items', v)} placeholder={hint('align-items')} options={['stretch', 'start', 'center', 'end']} />
            </Row>
          </>
        )}
        {(isFlex || isGrid) && (
          <Row label={t('Gap')} set={!!get('gap')}>
            <UnitInput value={get('gap')} onChange={(v) => set('gap', v)} placeholder={hint('gap')} />
          </Row>
        )}
        <Row label={t('Position')} set={!!get('position')}>
          <Select value={get('position')} onChange={(v) => set('position', v)} placeholder={hint('position')} options={['static', 'relative', 'absolute', 'fixed', 'sticky']} />
        </Row>
        {(get('position') || hint('position')) !== 'static' && (
          <div className="ve-grid4">
            {(['top', 'right', 'bottom', 'left'] as const).map((p) => (
              <input key={p} className="ve-input" title={p} placeholder={p} value={get(p)} onChange={(e) => set(p, /^-?\d+$/.test(e.target.value) ? e.target.value + 'px' : e.target.value)} />
            ))}
          </div>
        )}
        <div className="ve-grid2">
          <Row label="z-index" set={!!get('z-index')}>
            <Field value={get('z-index')} onChange={(v) => set('z-index', v)} placeholder={hint('z-index')} />
          </Row>
          <Row label={t('Overflow')} set={!!get('overflow')}>
            <Select value={get('overflow')} onChange={(v) => set('overflow', v)} placeholder="–" options={['visible', 'hidden', 'auto', 'clip']} />
          </Row>
        </div>
      </Section>

      <Section title={t('Spacing')} hint={t('margin / padding')}>
        <div className="ve-box">
          <span className="lbl">margin</span>
          <BoxSides prefix="margin" get={get} set={set} hint={hint} />
          <div className="ve-box inner">
            <span className="lbl">padding</span>
            <BoxSides prefix="padding" get={get} set={set} hint={hint} />
            <div className="core" />
          </div>
        </div>
        <div className="ve-grid2">
          <Field value={get('margin')} onChange={(v) => set('margin', v)} placeholder={'margin: ' + t('all')} mono />
          <Field value={get('padding')} onChange={(v) => set('padding', v)} placeholder={'padding: ' + t('all')} mono />
        </div>
        <button className="ve-btn small" onClick={() => ['margin-left', 'margin-right'].forEach((p) => set(p, 'auto'))}>
          {t('Center horizontally')}
        </button>
      </Section>

      <Section title={t('Size')}>
        <div className="ve-grid2">
          <Row label={t('Width')} set={!!get('width')}>
            <UnitInput value={get('width')} onChange={(v) => set('width', v)} placeholder={Math.round(selected.el.getBoundingClientRect().width) + ''} />
          </Row>
          <Row label={t('Height')} set={!!get('height')}>
            <UnitInput value={get('height')} onChange={(v) => set('height', v)} placeholder={Math.round(selected.el.getBoundingClientRect().height) + ''} />
          </Row>
          <Row label={t('Min W')} set={!!get('min-width')}>
            <UnitInput value={get('min-width')} onChange={(v) => set('min-width', v)} />
          </Row>
          <Row label={t('Max W')} set={!!get('max-width')}>
            <UnitInput value={get('max-width')} onChange={(v) => set('max-width', v)} placeholder={hint('max-width')} />
          </Row>
          <Row label={t('Min H')} set={!!get('min-height')}>
            <UnitInput value={get('min-height')} onChange={(v) => set('min-height', v)} />
          </Row>
          <Row label={t('Max H')} set={!!get('max-height')}>
            <UnitInput value={get('max-height')} onChange={(v) => set('max-height', v)} />
          </Row>
        </div>
        <Row label={t('Ratio')} set={!!get('aspect-ratio')}>
          <Select value={get('aspect-ratio')} onChange={(v) => set('aspect-ratio', v)} placeholder="auto" options={['1 / 1', '4 / 3', '3 / 2', '16 / 9', '21 / 9', '3 / 4', '9 / 16']} />
        </Row>
        {isImg && (
          <Row label={t('Fit')} set={!!get('object-fit')}>
            <Seg value={get('object-fit') || 'fill'} onChange={(v) => set('object-fit', v)} options={['fill', 'cover', 'contain'].map((v) => ({ value: v }))} />
          </Row>
        )}
      </Section>

      <Section title={t('Typography')}>
        <Row label={t('Font')} set={!!get('font-family')}>
          <Select value={get('font-family')} onChange={(v) => set('font-family', v)} placeholder={hint('font-family').split(',')[0]} options={[...fonts, { value: 'var(--sve-font-heading)', label: t('Heading font') }, { value: 'var(--sve-font-body)', label: t('Body font') }, { value: 'inherit', label: 'inherit' }, { value: 'system-ui, sans-serif', label: 'System' }, { value: 'Georgia, serif', label: 'Serif' }, { value: 'ui-monospace, monospace', label: 'Mono' }]} />
        </Row>
        <div className="ve-grid2">
          <Row label={t('Size')} set={!!get('font-size')}>
            <UnitInput value={get('font-size')} onChange={(v) => set('font-size', v)} placeholder={hint('font-size')} />
          </Row>
          <Row label={t('Weight')} set={!!get('font-weight')}>
            <Select value={get('font-weight')} onChange={(v) => set('font-weight', v)} placeholder={hint('font-weight')} options={['300', '400', '500', '600', '700', '800', '900']} />
          </Row>
          <Row label={t('Line')} set={!!get('line-height')}>
            <Field value={get('line-height')} onChange={(v) => set('line-height', v)} placeholder={hint('line-height')} />
          </Row>
          <Row label={t('Letter')} set={!!get('letter-spacing')}>
            <UnitInput value={get('letter-spacing')} onChange={(v) => set('letter-spacing', v)} placeholder={hint('letter-spacing')} units={['px', 'em']} />
          </Row>
        </div>
        <Row label={t('Color')} set={!!get('color')}>
          <ColorInput value={get('color')} onChange={(v) => set('color', v)} swatches={swatches} />
        </Row>
        <Row label={t('Align')} set={!!get('text-align')}>
          <Seg
            value={get('text-align') || hint('text-align')}
            onChange={(v) => set('text-align', v)}
            options={[
              { value: 'start', label: <AlignLeft size={13} /> },
              { value: 'center', label: <AlignCenter size={13} /> },
              { value: 'end', label: <AlignRight size={13} /> },
              { value: 'justify', label: <AlignJustify size={13} /> },
            ]}
          />
        </Row>
        <div className="ve-grid2">
          <Select value={get('text-transform')} onChange={(v) => set('text-transform', v)} placeholder={t('Case')} options={['none', 'uppercase', 'lowercase', 'capitalize']} />
          <Select value={get('text-decoration')} onChange={(v) => set('text-decoration', v)} placeholder={t('Decoration')} options={['none', 'underline', 'line-through', 'overline']} />
          <Select value={get('font-style')} onChange={(v) => set('font-style', v)} placeholder={t('Style')} options={['normal', 'italic']} />
          <Select value={get('white-space')} onChange={(v) => set('white-space', v)} placeholder={t('Wrapping')} options={['normal', 'nowrap', 'pre-line', 'balance']} />
        </div>
        <button className="ve-btn small" onClick={() => set('font-size', 'clamp(1.75rem, 1rem + 3vw, 3.5rem)')}>
          {t('Fluid size (responsive)')}
        </button>
      </Section>

      <Section title={t('Background')}>
        <Row label={t('Color')} set={!!get('background-color')}>
          <ColorInput value={get('background-color')} onChange={(v) => set('background-color', v)} swatches={swatches} />
        </Row>
        <Row label={t('Image')} set={!!get('background-image')}>
          <div style={{ display: 'flex', gap: 4 }}>
            <Field value={get('background-image')} onChange={(v) => set('background-image', v)} placeholder="url(…) / linear-gradient(…)" mono />
            <button
              className="ve-icon-btn"
              title={t('Media library')}
              onClick={() => useStore.setState({ modal: { type: 'media', onPick: (m) => { set('background-image', `url("${m.url}")`); set('background-size', 'cover'); set('background-position', 'center'); } } })}
            >
              <ImageIcon size={15} />
            </button>
          </div>
        </Row>
        <div className="ve-swatches">
          {GRADIENTS.map((g) => (
            <button key={g} style={{ background: g, width: 36, height: 22 }} title={g} onClick={() => set('background-image', g)} />
          ))}
        </div>
        {get('background-image') && (
          <div className="ve-grid3">
            <Select value={get('background-size')} onChange={(v) => set('background-size', v)} placeholder={t('Size')} options={['cover', 'contain', 'auto', '100% auto']} />
            <Select value={get('background-position')} onChange={(v) => set('background-position', v)} placeholder={t('Position')} options={['center', 'top', 'bottom', 'left', 'right']} />
            <Select value={get('background-repeat')} onChange={(v) => set('background-repeat', v)} placeholder={t('Repeat')} options={['no-repeat', 'repeat', 'repeat-x', 'repeat-y']} />
          </div>
        )}
      </Section>

      <Section title={t('Border')}>
        <Row label={t('Radius')} set={!!get('border-radius')}>
          <div style={{ display: 'grid', gap: 4 }}>
            <UnitInput value={get('border-radius')} onChange={(v) => set('border-radius', v)} placeholder={hint('border-radius')} />
            <Seg value={get('border-radius')} onChange={(v) => set('border-radius', v)} options={[{ value: '0', label: '0' }, { value: '6px', label: 'S' }, { value: '12px', label: 'M' }, { value: '24px', label: 'L' }, { value: '999px', label: '◯' }, { value: 'var(--radius)', label: 'T' }]} />
          </div>
        </Row>
        <div className="ve-grid3">
          <UnitInput value={get('border-width')} onChange={(v) => set('border-width', v)} placeholder={t('width')} units={[]} />
          <Select value={get('border-style')} onChange={(v) => set('border-style', v)} placeholder={t('style')} options={['none', 'solid', 'dashed', 'dotted', 'double']} />
          <Field value={get('border-color')} onChange={(v) => set('border-color', v)} placeholder={t('color')} mono />
        </div>
      </Section>

      <Section title={t('Effects')} open={false}>
        <Row label={t('Opacity')} set={!!get('opacity')}>
          <input type="range" min={0} max={1} step={0.05} value={get('opacity') || hint('opacity') || '1'} onChange={(e) => set('opacity', e.target.value)} style={{ width: '100%' }} />
        </Row>
        <Row label={t('Shadow')} set={!!get('box-shadow')}>
          <div style={{ display: 'grid', gap: 4 }}>
            <Seg value={Object.entries(SHADOWS).find(([, v]) => v === get('box-shadow'))?.[0] ?? ''} onChange={(k) => set('box-shadow', SHADOWS[k as keyof typeof SHADOWS])} options={Object.keys(SHADOWS).map((k) => ({ value: k }))} />
            <Field value={get('box-shadow')} onChange={(v) => set('box-shadow', v)} placeholder="0 10px 30px rgb(0 0 0 / .1)" mono />
          </div>
        </Row>
        <Row label={t('Transform')} set={!!get('transform')}>
          <Field value={get('transform')} onChange={(v) => set('transform', v)} placeholder="translateY(-4px) scale(1.02)" mono />
        </Row>
        <Row label={t('Transition')} set={!!get('transition')}>
          <Select value={get('transition')} onChange={(v) => set('transition', v)} placeholder="–" options={['all .2s ease', 'all .3s ease', 'all .5s cubic-bezier(.2,.8,.2,1)', 'transform .3s ease, box-shadow .3s ease', 'none']} />
        </Row>
        <Row label={t('Filter')} set={!!get('filter')}>
          <Field value={get('filter')} onChange={(v) => set('filter', v)} placeholder="blur(4px) grayscale(1)" mono />
        </Row>
        <Row label={t('Backdrop')} set={!!get('backdrop-filter')}>
          <Select value={get('backdrop-filter')} onChange={(v) => set('backdrop-filter', v)} placeholder="–" options={['blur(8px)', 'blur(16px) saturate(1.4)', 'none']} />
        </Row>
        <Row label={t('Cursor')} set={!!get('cursor')}>
          <Select value={get('cursor')} onChange={(v) => set('cursor', v)} placeholder="–" options={['pointer', 'default', 'text', 'grab', 'not-allowed']} />
        </Row>
      </Section>

      <Visibility />

      <Section title={t('All properties')} hint={Object.keys(bag).length ? String(Object.keys(bag).length) : ''} open={false}>
        {Object.entries(bag).map(([p, v]) => (
          <div key={p} className="ve-kv">
            <span className="ve-input code" style={{ display: 'flex', alignItems: 'center', overflow: 'hidden' }}>
              {p}
            </span>
            <Field value={v} onChange={(nv) => set(p, nv)} mono />
            <button className="ve-icon-btn" onClick={() => set(p, '')} title={t('Remove')}>
              <X size={13} />
            </button>
          </div>
        ))}
        <div className="ve-kv">
          <input className="ve-input code" list="ve-css-props" value={newProp} placeholder={t('property')} onChange={(e) => setNewProp(e.target.value)} />
          <input
            className="ve-input code"
            placeholder={t('value + Enter')}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && newProp.trim()) {
                set(newProp.trim(), (e.target as HTMLInputElement).value);
                (e.target as HTMLInputElement).value = '';
                setNewProp('');
              }
            }}
          />
          <Plus size={14} className="ve-muted" />
        </div>
        <datalist id="ve-css-props">
          {CSS_PROPS.map((p) => (
            <option key={p} value={p} />
          ))}
        </datalist>
        {edit && <div className="ve-faint">{t('Saved for:')} {edit.scope === 'page' ? t('this page') : edit.scope === 'template' ? t('this template') : t('the whole site')}</div>}
      </Section>
    </div>
  );
};

const Visibility = () => {
  const selected = useStore((s) => s.selected);
  const updateEdit = useStore((s) => s.updateEdit);
  const { edit } = useStyles();
  if (!selected) return null;
  const toggle = (k: 'desktop' | 'tablet' | 'mobile') =>
    updateEdit(selected.sel, (e) => {
      const hide = { ...(e.hide ?? {}) };
      if (hide[k]) delete hide[k];
      else hide[k] = true;
      e.hide = Object.keys(hide).length ? hide : undefined;
    });
  return (
    <Section title={t('Visibility')} hint={t('per device')}>
      <div className="ve-grid3">
        {(['desktop', 'tablet', 'mobile'] as const).map((k) => (
          <button key={k} className={'ve-btn small' + (edit?.hide?.[k] ? ' danger' : '')} onClick={() => toggle(k)}>
            {edit?.hide?.[k] ? <EyeOff size={13} /> : <Eye size={13} />} {t(k[0].toUpperCase() + k.slice(1))}
          </button>
        ))}
      </div>
    </Section>
  );
};

export const STATES: { value: StateName; label: string }[] = [
  { value: 'normal', label: 'Normal' },
  { value: 'hover', label: 'Hover' },
  { value: 'focus-visible', label: 'Focus' },
  { value: 'active', label: 'Active' },
  { value: 'before', label: '::before' },
  { value: 'after', label: '::after' },
];
