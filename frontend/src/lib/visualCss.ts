/**
 * Visual editor data model + CSS compiler. Shared by the editor (live preview) and — through the
 * compiled strings it saves — by the PHP side that prints the rules on the public site.
 */

export type Device = 'base' | 'tablet' | 'mobile';
export type HideKey = 'desktop' | 'tablet' | 'mobile';
/** page: one URL · template: every item a theme template renders (all products…) · site: everywhere. */
export type Scope = 'page' | 'template' | 'site';
export type State = 'hover' | 'focus' | 'active';

export const STATES: State[] = ['hover', 'focus', 'active'];

type Styles = Partial<Record<Device, Record<string, string>>>;

export const DEVICES: Device[] = ['base', 'tablet', 'mobile'];

/** Below Tailwind's `lg` / `md` breakpoints, so the preview widths line up with the site's own layout. */
export const MEDIA: Record<Device, string | null> = {
  base: null,
  tablet: '(max-width: 1023px)',
  mobile: '(max-width: 767px)',
};

const HIDE_MEDIA: Record<HideKey, string> = {
  desktop: '(min-width: 1024px)',
  tablet: '(min-width: 768px) and (max-width: 1023px)',
  mobile: '(max-width: 767px)',
};

export type Edit = {
  id: string;
  scope: Scope;
  /** Page key (see pageKey) the edit belongs to; used when scope is "page". */
  page: string;
  /** Template key ("single:product"); used when scope is "template". */
  tpl?: string;
  sel: string;
  /** Human readable name, e.g. `h1.hero-title`. */
  label: string;
  styles: Styles;
  /** Styles for :hover / :focus / :active, same shape as `styles`. */
  states?: Partial<Record<State, Styles>>;
  /** Free CSS; `selector` stands for the edit's selector. */
  css: string;
  hide?: Partial<Record<HideKey, boolean>>;
  text?: string;
  html?: string;
  /** null removes the attribute. */
  attrs?: Record<string, string | null>;
  /** Filled in on save: the CSS the public site prints. */
  compiled?: string;
};

/** Where something goes relative to its anchor element. */
export type Pos = 'before' | 'after' | 'prepend' | 'append';

type OpBase = { id: string; scope: Scope; page: string; tpl?: string; label: string };

/**
 * Structural changes, replayed in order on the pristine page. `sel` uses the original structure (the
 * editor never counts inserted/moved elements, which carry data-ve-id), so selectors stay valid.
 */
export type Op =
  | (OpBase & { kind: 'insert'; sel: string; pos: Pos; html: string; comp?: string; exec?: boolean })
  | (OpBase & { kind: 'move'; sel: string; to: string; pos: Pos })
  | (OpBase & { kind: 'remove'; sel: string })
  | (OpBase & { kind: 'tag'; sel: string; tag: string });

/** A saved snippet that can be inserted anywhere; its markup and CSS update everywhere it is used. */
export type ComponentKind = 'html' | 'embed' | 'jsonld';

export type Component = { id: string; name: string; html: string; css: string; kind?: ComponentKind };

export type Seo = {
  title?: string;
  description?: string;
  image?: string;
  canonical?: string;
  noindex?: boolean;
  nofollow?: boolean;
  /** Extra structured data (JSON-LD) printed in <head>. */
  jsonld?: string;
};

export type VisualDoc = {
  v: 1;
  css: string;
  edits: Edit[];
  ops?: Op[];
  components?: Component[];
  /** Page key → SEO overrides. */
  seo?: Record<string, Seo>;
  /** Who may open the editor: administrators only (default) or editors too. */
  access?: 'admin' | 'editor';
};

export const emptyDoc = (): VisualDoc => ({ v: 1, css: '', edits: [], ops: [], components: [], seo: {}, access: 'admin' });

/** Whether an edit or op belongs on the page being shown. */
export const appliesHere = (item: { scope: Scope; page: string; tpl?: string }, page: string, tpl = ''): boolean =>
  item.scope === 'site' ||
  (item.scope === 'template' && tpl !== '' && item.tpl === tpl) ||
  (item.scope === 'page' && item.page === page);

/** Key under which a template's SEO settings are stored in doc.seo. */
export const templateSeoKey = (tpl: string): string => `tpl:${tpl}`;

export const opsForPage = (doc: VisualDoc, page: string, tpl = ''): Op[] =>
  (doc.ops ?? []).filter((op) => appliesHere(op, page, tpl));

/** Ops as the applier wants them: component references resolved to their current markup. */
export const resolveOps = (doc: VisualDoc, page: string, tpl = ''): Op[] =>
  opsForPage(doc, page, tpl).map((op) => {
    if (op.kind !== 'insert' || !op.comp) return op;
    const component = doc.components?.find((entry) => entry.id === op.comp);
    // Embed snippets (iframes, widgets, scripts) must run; other components are plain markup.
    return component ? { ...op, html: component.html, ...(component.kind === 'embed' ? { exec: true } : {}) } : op;
  });

/** Normalises a pathname into the key used to scope edits to a page ("/", "/tours/", "/tours/phuket/"). */
export const pageKey = (pathname: string, homePath = ''): string => {
  const base = homePath.replace(/\/+$/, '');
  const rel = (base && pathname.startsWith(base) ? pathname.slice(base.length) : pathname).replace(
    /^\/+|\/+$/g,
    '',
  );
  return rel === '' ? '/' : `/${rel}/`;
};

const declarations = (styles: Record<string, string> | undefined): string =>
  Object.entries(styles ?? {})
    .filter(([prop, value]) => prop.trim() !== '' && value.trim() !== '')
    .map(([prop, value]) => `${prop}:${value.replace(/!important/gi, '').trim()} !important`)
    .join(';');

const wrap = (device: Device, rule: string): string => {
  const media = MEDIA[device];
  return media ? `@media ${media}{${rule}}` : rule;
};

/**
 * `live` is the editor preview: state rules also match an element carrying data-ve-force="<state>",
 * so a hover style can be seen without holding the pointer over the element.
 */
export const compileEdit = (edit: Edit, live = false): string => {
  const out: string[] = [];

  for (const device of DEVICES) {
    const body = declarations(edit.styles[device]);
    if (body) out.push(wrap(device, `${edit.sel}{${body}}`));
  }

  for (const state of STATES) {
    for (const device of DEVICES) {
      const body = declarations(edit.states?.[state]?.[device]);
      if (!body) continue;
      const selector = live
        ? `${edit.sel}:${state},${edit.sel}[data-ve-force~="${state}"]`
        : `${edit.sel}:${state}`;
      out.push(wrap(device, `${selector}{${body}}`));
    }
  }

  for (const key of Object.keys(HIDE_MEDIA) as HideKey[]) {
    if (edit.hide?.[key]) out.push(`@media ${HIDE_MEDIA[key]}{${edit.sel}{display:none !important}}`);
  }

  const custom = edit.css.trim();
  if (custom) out.push(custom.replace(/\bselector\b/g, edit.sel));

  return out.join('\n');
};

export const editsForPage = (doc: VisualDoc, page: string, tpl = ''): Edit[] =>
  doc.edits.filter((edit) => appliesHere(edit, page, tpl));

/** Everything the page should print: global CSS first, then each edit in order. */
export const buildCss = (doc: VisualDoc, page: string, live = false, tpl = ''): string =>
  [
    ...(doc.components ?? []).map((component) => component.css.trim()),
    doc.css.trim(),
    ...editsForPage(doc, page, tpl).map((edit) => compileEdit(edit, live)),
  ]
    .filter(Boolean)
    .join('\n');

export const hasContent = (edit: Edit): boolean =>
  edit.text !== undefined || edit.html !== undefined || Object.keys(edit.attrs ?? {}).length > 0;

export const isEmptyEdit = (edit: Edit): boolean =>
  !hasContent(edit) &&
  edit.css.trim() === '' &&
  !Object.values(edit.hide ?? {}).some(Boolean) &&
  !DEVICES.some((device) => Object.keys(edit.styles[device] ?? {}).length > 0) &&
  !STATES.some((state) => DEVICES.some((device) => Object.keys(edit.states?.[state]?.[device] ?? {}).length > 0));

/** What gets sent to the server: structured edits plus the CSS compiled from them. */
export const serializeDoc = (doc: VisualDoc): VisualDoc => ({
  ...doc,
  edits: doc.edits.map((edit) => ({ ...edit, compiled: compileEdit(edit) })),
});
