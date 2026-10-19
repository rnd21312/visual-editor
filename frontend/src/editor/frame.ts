// Controls the preview iframe: applies the working document live (CSS, content, structure, motions, tokens)
// and finds out what the page is made of (page key, template, builder sources).
import { createApplier, type Applier } from '../shared/apply';
import { compileEdit } from '../shared/compile';
import { playMotions } from '../shared/motion';
import type { Doc, Edit, Op } from '../shared/types';
import { useStore, type Ctx, type Owner } from './store';

type FrameState = {
  iframe: HTMLIFrameElement | null;
  doc: Document | null;
  applier: Applier | null;
  stopMotions: () => void;
  motionTimer: number;
  lastMotionKey: string;
};

const fs: FrameState = { iframe: null, doc: null, applier: null, stopMotions: () => undefined, motionTimer: 0, lastMotionKey: '' };

/** Server-rendered markup of components used by structural edits (bindings and props resolved). */
const rendered = new Map<string, string>();
const pending = new Set<string>();

const renderComponent = (key: string, comp: string, props: Record<string, unknown> | undefined) => {
  if (pending.has(key)) return;
  pending.add(key);
  const s = useStore.getState();
  s.api
    .post<{ html: string }>('render', { component: comp, props: props ?? {}, post: s.ctx?.post ?? 0 })
    .then((r) => {
      rendered.set(key, r.html);
      pending.delete(key);
      const st = useStore.getState();
      if (syncFrame(st.doc, st.ctx) === 'reload') st.reload();
    })
    .catch(() => pending.delete(key));
};

/** Forget rendered components (after a component changed). */
export const invalidateComponents = (): void => rendered.clear();

export const frameDoc = (): Document | null => fs.doc;
export const frameWin = (): (Window & typeof globalThis) | null => (fs.doc?.defaultView as (Window & typeof globalThis) | null) ?? null;
export const frameEl = (): HTMLIFrameElement | null => fs.iframe;

export const applies = (item: { scope: string; page: string; tpl: string }, ctx: Ctx | null): boolean => {
  if (!ctx) return false;
  if (item.scope === 'site') return true;
  if (item.scope === 'template') return item.tpl !== '' && item.tpl === ctx.template.key;
  return item.page === ctx.page;
};

const readCtx = (doc: Document): Ctx | null => {
  const meta = doc.querySelector('meta[name="sve-context"]');
  if (!meta) return null;
  try {
    return JSON.parse(meta.getAttribute('content') ?? '') as Ctx;
  } catch {
    return null;
  }
};

const ensureStyle = (doc: Document, id: string): HTMLStyleElement => {
  let el = doc.getElementById(id) as HTMLStyleElement | null;
  if (!el) {
    el = doc.createElement('style');
    el.id = id;
    (doc.head ?? doc.documentElement).append(el);
  }
  return el;
};

/** Editor-only styles inside the preview (selection affordances, inline editing). */
const EDITOR_CSS = `
[contenteditable="true"][data-sve-editing]{outline:2px dashed #6366f1!important;outline-offset:3px;cursor:text!important;min-width:1em}
.sve-missing{padding:12px;border:1px dashed #f59e0b;color:#92400e;background:#fffbeb;font:13px system-ui}
html.sve-design *{cursor:default}
html.sve-design a,html.sve-design button{pointer-events:auto}
`;

export const attachFrame = (iframe: HTMLIFrameElement): void => {
  fs.iframe = iframe;
};

/** Called on every load of the preview. */
export const onFrameLoad = (): Ctx | null => {
  const doc = fs.iframe?.contentDocument ?? null;
  fs.applier?.disconnect();
  fs.stopMotions();
  fs.doc = doc;
  fs.lastMotionKey = '';
  if (!doc) return null;
  const ctx = readCtx(doc);
  ensureStyle(doc, 'sve-editor-ui').textContent = EDITOR_CSS;
  fs.applier = createApplier(doc);
  return ctx;
};

const fontLinks = (doc: Document, d: Doc) => {
  const google = d.tokens.fonts.filter((f) => f.source === 'google' || f.source === 'bunny');
  const id = 'sve-preview-fonts';
  doc.getElementById(id)?.remove();
  if (!google.length) return;
  const families = google.map((f) => `family=${f.family.replace(/ /g, '+')}:wght@${(f.weights.length ? f.weights : [400, 700]).sort().join(';')}`).join('&');
  const link = doc.createElement('link');
  link.id = id;
  link.rel = 'stylesheet';
  link.href = `https://fonts.googleapis.com/css2?${families}&display=swap`;
  doc.head.append(link);
};

/**
 * Applies the working document to the preview. Returns "reload" when structural edits changed in a way that
 * needs a fresh page.
 */
export const syncFrame = (d: Doc, ctx: Ctx | null): 'ok' | 'reload' => {
  const doc = fs.doc;
  if (!doc || !fs.applier || !ctx) return 'ok';

  const vars = { ...d.tokens.vars };
  d.tokens.fonts.forEach((f) => {
    if (f.role) vars[`--sve-font-${f.role}`] = `"${f.family}", system-ui, sans-serif`;
  });
  const tokens = Object.keys(vars).length ? `:root{${Object.entries(vars).map(([k, v]) => `${k}:${v}`).join(';')}}` : '';
  ensureStyle(doc, 'sve-tokens-live').textContent = tokens;
  fontLinks(doc, d);

  const edits = d.edits.filter((e) => applies(e, ctx));
  const css: string[] = [];
  if (d.tailwind.enabled && d.tailwind.css) css.push(d.tailwind.css);
  d.components.forEach((c) => c.css && css.push(c.css));
  if (d.css) css.push(d.css);
  edits.forEach((e: Edit) => css.push(e.compiled ?? compileEdit(e, d.settings.breakpoints)));
  ensureStyle(doc, 'sve-preview').textContent = css.join('\n');

  const content = edits
    .filter((e) => e.text !== undefined || e.html !== undefined || (e.attrs && Object.keys(e.attrs).length))
    .map((e) => ({ sel: e.sel, text: e.text, html: e.html, attrs: e.attrs, t: 1 }));
  const components = new Map(d.components.map((c) => [c.id, c]));
  const ops = d.ops
    .filter((o: Op) => applies(o, ctx))
    .map((o) => {
      const c = o.comp ? components.get(o.comp) : undefined;
      let html = o.html;
      if (c) {
        // Components inserted on theme pages: rendered by the server (bindings, props), raw until then.
        const key = c.id + ':' + (c.updated ?? '') + ':' + c.html.length + ':' + JSON.stringify(o.props ?? {});
        html = rendered.get(key) ?? c.html;
        if (!rendered.has(key)) renderComponent(key, c.id, o.props);
      }
      return { id: o.id, kind: o.kind, sel: o.sel, pos: o.pos, html, exec: o.exec, to: o.to, tag: o.tag, t: 1 };
    });
  const result = fs.applier.set(content, ops);
  if (result === 'reload') return 'reload';
  (frameWin() as (Window & { __sveMountIslands?: () => void }) | null)?.__sveMountIslands?.();

  // Motions replay when they change (not on every keystroke elsewhere).
  const motions = edits.filter((e) => e.motion).map((e) => ({ sel: e.sel, m: e.motion! }));
  const key = JSON.stringify(motions);
  if (key !== fs.lastMotionKey) {
    fs.lastMotionKey = key;
    window.clearTimeout(fs.motionTimer);
    fs.motionTimer = window.setTimeout(() => {
      fs.stopMotions();
      const win = frameWin();
      if (win) fs.stopMotions = playMotions(win as never, motions, false);
    }, 250);
  }
  return 'ok';
};

/** Plays one motion now (preview button). */
export const previewMotion = (sel: string, m: NonNullable<Edit['motion']>): void => {
  const win = frameWin();
  if (!win) return;
  const stop = playMotions(win as never, [{ sel, m: { ...m, trigger: m.trigger === 'scroll' ? 'load' : m.trigger } }], false);
  window.setTimeout(stop, (m.duration + m.delay) * 1000 + 1500);
};

/* ------------------------------------------------------------------ ownership of elements */

/**
 * Which source an element of the preview comes from: a builder layout (page / template / part), a component,
 * an inserted element (op), or the theme.
 */
export const ownerOf = (el: Element): Owner => {
  const sid = el.getAttribute('data-sid');
  const s = useStore.getState();
  if (sid) {
    const needle = `data-sid="${sid}"`;
    for (const c of s.doc.components) if (c.html.includes(needle)) return { kind: 'component', id: c.id };
    for (const o of s.doc.ops) if (o.html?.includes(needle)) return { kind: 'op', id: o.id };
    const region = el.closest('[data-sve-source]');
    if (region) return { kind: 'post', id: Number(region.getAttribute('data-sve-source')) };
  }
  const inserted = el.closest('[data-ve-id]');
  if (inserted) {
    const id = inserted.getAttribute('data-ve-id') ?? '';
    if (s.doc.ops.some((o) => o.id === id && o.kind === 'insert')) return { kind: 'op', id };
  }
  return { kind: 'theme' };
};

/** Finds an element of the preview again after a reload. */
export const findAgain = (sel: string): Element | null => {
  try {
    return fs.doc?.querySelector(sel) ?? null;
  } catch {
    return null;
  }
};
