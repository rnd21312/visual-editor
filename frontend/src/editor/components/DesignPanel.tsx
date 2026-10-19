import { useState } from 'react';
import { Plus, X, Wand2, RefreshCw } from 'lucide-react';
import { useStore, flushWorking, newId } from '../store';
import { Section, Row, Field, ColorInput, Select, Code, Seg } from './ui';
import type { Font, Script } from '../../shared/types';
import { t } from '../i18n';

const PALETTES: Record<string, Record<string, string>> = {
  Ocean: { '--color-primary': '#0e7490', '--color-accent': '#f59e0b', '--color-text': '#0f172a', '--color-muted': '#475569', '--color-soft': '#ecfeff', '--color-surface': '#ffffff', '--color-border': '#e2e8f0' },
  Sunset: { '--color-primary': '#e11d48', '--color-accent': '#f97316', '--color-text': '#1c1917', '--color-muted': '#57534e', '--color-soft': '#fff1f2', '--color-surface': '#ffffff', '--color-border': '#fde2e4' },
  Forest: { '--color-primary': '#15803d', '--color-accent': '#ca8a04', '--color-text': '#14231a', '--color-muted': '#4b5d52', '--color-soft': '#f0fdf4', '--color-surface': '#ffffff', '--color-border': '#dcfce7' },
  Indigo: { '--color-primary': '#4f46e5', '--color-accent': '#14b8a6', '--color-text': '#0f172a', '--color-muted': '#64748b', '--color-soft': '#f1f5f9', '--color-surface': '#ffffff', '--color-border': '#e2e8f0' },
  'shadcn neutral': { '--color-primary': '#18181b', '--color-accent': '#2563eb', '--color-text': '#09090b', '--color-muted': '#71717a', '--color-soft': '#f4f4f5', '--color-surface': '#ffffff', '--color-border': '#e4e4e7', '--radius': '10px' },
  Midnight: { '--color-primary': '#a78bfa', '--color-accent': '#22d3ee', '--color-text': '#f8fafc', '--color-muted': '#94a3b8', '--color-soft': '#111827', '--color-surface': '#0b1020', '--color-border': '#1f2937' },
};

const FONTS = ['Vazirmatn', 'Inter', 'Poppins', 'Manrope', 'DM Sans', 'Plus Jakarta Sans', 'Outfit', 'Sora', 'Montserrat', 'Lato', 'Roboto', 'Playfair Display', 'Lora', 'Noto Sans Arabic', 'Estedad', 'Lalezar', 'Markazi Text', 'Amiri', 'JetBrains Mono'];

export const DesignPanel = () => {
  const doc = useStore((s) => s.doc);
  const cfg = useStore((s) => s.cfg);
  const setDoc = useStore((s) => s.setDoc);
  const [newVar, setNewVar] = useState('');
  const vars = doc.tokens.vars;

  const setVar = (k: string, v: string) =>
    setDoc((d) => {
      if (v === '') delete d.tokens.vars[k];
      else d.tokens.vars[k] = v;
    }, 'var:' + k);

  const setFonts = async (fonts: Font[]) => {
    setDoc((d) => void (d.tokens.fonts = fonts));
  };
  const role = (r: Font['role']) => doc.tokens.fonts.find((f) => f.role === r)?.family ?? '';
  const setRole = (r: Font['role'], family: string) => {
    const others = doc.tokens.fonts.filter((f) => f.role !== r);
    void setFonts(family ? [...others, { family, source: 'google', weights: [400, 600, 800], role: r }] : others);
  };

  return (
    <div className="ve-scroll">
      <Section title={t('Colors')} right={<Select value="" onChange={(p) => p && setDoc((d) => void (d.tokens.vars = { ...d.tokens.vars, ...PALETTES[p] }))} placeholder={t('Palette…')} options={Object.keys(PALETTES)} />}>
        {Object.entries(vars)
          .filter(([k, v]) => /color|bg|brand/i.test(k) || /^#|^rgb|^hsl|^oklch/i.test(v))
          .map(([k, v]) => (
            <Row key={k} label={k.replace(/^--(color-)?/, '')} title={k}>
              <div style={{ display: 'flex', gap: 4 }}>
                <ColorInput value={v} onChange={(nv) => setVar(k, nv)} />
                <button className="ve-icon-btn" onClick={() => setVar(k, '')}>
                  <X size={13} />
                </button>
              </div>
            </Row>
          ))}
        <div style={{ display: 'flex', gap: 4 }}>
          <input className="ve-input code" placeholder="--color-brand" value={newVar} onChange={(e) => setNewVar(e.target.value)} />
          <button className="ve-btn small" onClick={() => { if (newVar) { setVar(newVar.startsWith('--') ? newVar : '--' + newVar, '#4f46e5'); setNewVar(''); } }}>
            <Plus size={13} />
          </button>
        </div>
        <div className="ve-faint">{t('Use them anywhere as var(--color-primary). Changing a token restyles the whole site.')}</div>
      </Section>

      <Section title={t('Typography')}>
        <Row label={t('Headings')}>
          <input className="ve-input" list="ve-fonts" value={role('heading')} onChange={(e) => setRole('heading', e.target.value)} placeholder="Inter" />
        </Row>
        <Row label={t('Body')}>
          <input className="ve-input" list="ve-fonts" value={role('body')} onChange={(e) => setRole('body', e.target.value)} placeholder="Vazirmatn" />
        </Row>
        <datalist id="ve-fonts">
          {FONTS.map((f) => (
            <option key={f} value={f} />
          ))}
        </datalist>
        <button className="ve-btn small" onClick={() => setDoc((d) => void (d.css = (d.css ? d.css + '\n' : '') + 'body{font-family:var(--sve-font-body,inherit)}h1,h2,h3,h4,h5,h6{font-family:var(--sve-font-heading,inherit)}'))}>
          <Wand2 size={13} /> {t('Apply fonts to the whole theme')}
        </button>
        <div className="ve-faint">{t('Google Fonts names. They load only on pages of this site, with font-display: swap.')}</div>
      </Section>

      <Section title={t('Shape & spacing')}>
        {(['--radius', '--container', '--space', '--shadow'] as const).map((k) => (
          <Row key={k} label={k.slice(2)}>
            <Field value={vars[k] ?? ''} onChange={(v) => setVar(k, v)} placeholder={{ '--radius': '14px', '--container': '1200px', '--space': '1rem', '--shadow': '0 10px 30px rgb(0 0 0/.08)' }[k]} mono />
          </Row>
        ))}
        {Object.entries(vars)
          .filter(([k, v]) => !/color|bg|brand/i.test(k) && !/^#|^rgb|^hsl|^oklch/i.test(v) && !['--radius', '--container', '--space', '--shadow'].includes(k))
          .map(([k, v]) => (
            <Row key={k} label={k.slice(2)}>
              <div style={{ display: 'flex', gap: 4 }}>
                <Field value={v} onChange={(nv) => setVar(k, nv)} mono />
                <button className="ve-icon-btn" onClick={() => setVar(k, '')}>
                  <X size={13} />
                </button>
              </div>
            </Row>
          ))}
      </Section>

      <Section title={t('Breakpoints')} open={false}>
        <div className="ve-grid2">
          <Row label={t('Tablet ≤')}>
            <Field value={String(doc.settings.breakpoints.tablet)} onChange={(v) => setDoc((d) => void (d.settings.breakpoints.tablet = Number(v) || 1023))} />
          </Row>
          <Row label={t('Mobile ≤')}>
            <Field value={String(doc.settings.breakpoints.mobile)} onChange={(v) => setDoc((d) => void (d.settings.breakpoints.mobile = Number(v) || 767))} />
          </Row>
        </div>
      </Section>

      <Section title={t('Global CSS')} open={false}>
        <Code value={doc.css} onChange={(v) => setDoc((d) => void (d.css = v), 'global-css')} lang="css" height={260} />
      </Section>

      <TailwindSection />
      <LibrariesSection />
      {cfg.user.trusted && <ScriptsSection />}
    </div>
  );
};

const TailwindSection = () => {
  const doc = useStore((s) => s.doc);
  const setDoc = useStore((s) => s.setDoc);
  const [busy, setBusy] = useState(false);
  const [preflight, setPreflight] = useState(false);
  const build = async () => {
    setBusy(true);
    try {
      const { compileTailwind } = await import('../tailwind');
      const css = await compileTailwind(useStore.getState(), preflight);
      setDoc((d) => {
        d.tailwind.enabled = true;
        d.tailwind.css = css;
      });
      useStore.getState().toast(t('Tailwind CSS compiled ({kb} KB).', { kb: Math.round(css.length / 1024) }), 'success');
    } catch (e) {
      useStore.getState().toast(e instanceof Error ? e.message : String(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Section title="Tailwind CSS" hint={doc.tailwind.enabled ? t('on') : ''} open={false}>
      <div className="ve-faint">{t('Use Tailwind classes (and shadcn-style markup) anywhere. The editor compiles only the classes you use into plain CSS — the site never loads the Tailwind compiler.')}</div>
      <label className="ve-check">
        <input type="checkbox" checked={doc.tailwind.enabled} onChange={(e) => setDoc((d) => void (d.tailwind.enabled = e.target.checked))} /> {t('Enabled')}
      </label>
      <label className="ve-check">
        <input type="checkbox" checked={preflight} onChange={(e) => setPreflight(e.target.checked)} /> {t('Include Preflight (resets theme styles — only for builder-only sites)')}
      </label>
      <Row label={t('Theme')}>
        <Code value={doc.tailwind.config || '@theme {\n  --color-brand: var(--color-primary);\n}'} onChange={(v) => setDoc((d) => void (d.tailwind.config = v), 'tw-config')} lang="css" height={110} />
      </Row>
      <button className="ve-btn small primary" disabled={busy} onClick={() => void build()}>
        <RefreshCw size={13} /> {busy ? t('Compiling…') : t('Compile used classes')}
      </button>
    </Section>
  );
};

const LibrariesSection = () => {
  const doc = useStore((s) => s.doc);
  const cfg = useStore((s) => s.cfg);
  const ctx = useStore((s) => s.ctx);
  const setDoc = useStore((s) => s.setDoc);
  const [custom, setCustom] = useState({ url: '', type: 'script' });
  const use = (id: string) => doc.libraries.find((l) => l.id === id);
  const toggle = async (id: string, on: boolean, scope: 'site' | 'page' = 'site') => {
    setDoc((d) => {
      const existing = d.libraries.find((l) => l.id === id);
      if (existing) {
        existing.enabled = on;
        existing.scope = scope;
        if (scope === 'page' && ctx && !existing.pages.includes(ctx.page)) existing.pages.push(ctx.page);
      } else d.libraries.push({ id, enabled: on, scope, pages: scope === 'page' && ctx ? [ctx.page] : [], tpls: [] });
    });
    await flushWorking();
    useStore.getState().reload();
  };
  const groups = Array.from(new Set(cfg.libraries.map((l) => l.category)));
  return (
    <Section title={t('Libraries')} hint={String(doc.libraries.filter((l) => l.enabled).length)} open={false}>
      {groups.map((g) => (
        <div key={g} style={{ display: 'grid', gap: 4 }}>
          <div className="ve-h">{g}</div>
          {cfg.libraries
            .filter((l) => l.category === g)
            .map((l) => {
              const u = use(l.id);
              return (
                <div key={l.id} className="ve-item" title={l.usage}>
                  <label className="ve-check" style={{ color: 'var(--ve-text)' }}>
                    <input type="checkbox" checked={!!u?.enabled} onChange={(e) => void toggle(l.id, e.target.checked, u?.scope === 'page' ? 'page' : 'site')} /> {l.name}
                  </label>
                  <span className="meta">{l.version}</span>
                  {u?.enabled && (
                    <Seg value={u.scope === 'page' ? 'page' : 'site'} onChange={(v) => void toggle(l.id, true, v as 'site' | 'page')} options={[{ value: 'site', label: t('site') }, { value: 'page', label: t('page') }]} />
                  )}
                </div>
              );
            })}
        </div>
      ))}
      <div className="ve-faint">{t('GSAP loads automatically for elements with motion; React for islands.')}</div>
      {cfg.user.trusted && (
        <>
          <div className="ve-h">{t('Custom library (https)')}</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 90px auto', gap: 4 }}>
            <input className="ve-input code" placeholder="https://cdn.jsdelivr.net/npm/…" value={custom.url} onChange={(e) => setCustom({ ...custom, url: e.target.value })} />
            <select className="ve-select" value={custom.type} onChange={(e) => setCustom({ ...custom, type: e.target.value })}>
              <option value="script">script</option>
              <option value="module">module</option>
              <option value="style">style</option>
            </select>
            <button
              className="ve-btn small"
              onClick={() => {
                if (!custom.url.startsWith('https://')) return;
                setDoc((d) => void d.libraries.push({ id: 'custom-' + newId().slice(0, 6), name: custom.url.split('/').pop() ?? 'custom', url: custom.url, type: custom.type as 'script', enabled: true, scope: 'site', pages: [], tpls: [] }));
                setCustom({ url: '', type: 'script' });
              }}
            >
              <Plus size={13} />
            </button>
          </div>
          {doc.libraries
            .filter((l) => l.url)
            .map((l) => (
              <div key={l.id} className="ve-item">
                <span className="t">{l.name}</span>
                <button className="ve-icon-btn" style={{ marginInlineStart: 'auto' }} onClick={() => setDoc((d) => void (d.libraries = d.libraries.filter((x) => x.id !== l.id)))}>
                  <X size={13} />
                </button>
              </div>
            ))}
        </>
      )}
    </Section>
  );
};

const ScriptsSection = () => {
  const doc = useStore((s) => s.doc);
  const setDoc = useStore((s) => s.setDoc);
  const target = useStore((s) => s.target);
  const [open, setOpen] = useState<string | null>(null);
  const patch = (id: string, p: Partial<Script>) => setDoc((d) => void (d.scripts = d.scripts.map((x) => (x.id === id ? { ...x, ...p } : x))), 'script:' + id);
  return (
    <Section title={t('Custom code')} hint={String(doc.scripts.length)} open={false}>
      {doc.scripts.map((sc) => (
        <div key={sc.id} style={{ display: 'grid', gap: 4 }}>
          <div className="ve-item" onClick={() => setOpen(open === sc.id ? null : sc.id)}>
            <input type="checkbox" checked={sc.enabled} onClick={(e) => e.stopPropagation()} onChange={(e) => patch(sc.id, { enabled: e.target.checked })} />
            <span className="t">{sc.label || t('Script')}</span>
            <span className="meta">{sc.scope} · {sc.where}</span>
            <button className="ve-icon-btn" onClick={(e) => { e.stopPropagation(); setDoc((d) => void (d.scripts = d.scripts.filter((x) => x.id !== sc.id))); }}>
              <X size={13} />
            </button>
          </div>
          {open === sc.id && (
            <>
              <div className="ve-grid3">
                <Field value={sc.label} onChange={(label) => patch(sc.id, { label })} placeholder={t('Name')} />
                <Select value={sc.where} onChange={(where) => patch(sc.id, { where: where as Script['where'] })} options={['footer', 'head']} />
                <Select value={sc.scope} onChange={(scope) => patch(sc.id, { scope: scope as Script['scope'] })} options={['site', 'page', 'template']} />
              </div>
              <Code value={sc.code} onChange={(code) => patch(sc.id, { code })} lang="js" height={200} />
            </>
          )}
        </div>
      ))}
      <button
        className="ve-btn small"
        onClick={() => {
          const id = newId();
          setDoc((d) => void d.scripts.push({ id, label: t('New script'), code: '// runs on the public site\n', where: 'footer', module: false, enabled: true, ...target(), scope: 'site' }));
          setOpen(id);
        }}
      >
        <Plus size={13} /> {t('Add script')}
      </button>
      <div className="ve-faint">{t('Analytics, chat widgets, GSAP timelines… Administrators only.')}</div>
    </Section>
  );
};
