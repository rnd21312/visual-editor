import type { Edit, Op, Pos, Scope, VisualDoc } from '@/lib/visualCss';
import { pickElement } from './selector';
import { newId } from './state';

/* ---------- palette ---------- */

export type Snippet = { id: string; label: string; group: string; html: string };

export const SNIPPETS: Snippet[] = [
  { id: 'section', label: 'Section', group: 'Layout', html: '<section class="mx-auto max-w-6xl px-4 py-16"><h2>New section</h2><p>Write something here.</p></section>' },
  { id: 'container', label: 'Container', group: 'Layout', html: '<div class="mx-auto max-w-6xl px-4 py-6">Container</div>' },
  { id: 'columns', label: '2 columns', group: 'Layout', html: '<div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 md:grid-cols-2"><div><h3>Left</h3><p>Text</p></div><div><h3>Right</h3><p>Text</p></div></div>' },
  { id: 'grid3', label: '3 columns', group: 'Layout', html: '<div class="mx-auto grid max-w-6xl gap-6 px-4 py-10 md:grid-cols-3"><div><h3>One</h3><p>Text</p></div><div><h3>Two</h3><p>Text</p></div><div><h3>Three</h3><p>Text</p></div></div>' },
  { id: 'article', label: 'Article', group: 'Layout', html: '<article><h2>Title</h2><p>Text</p></article>' },
  { id: 'aside', label: 'Aside', group: 'Layout', html: '<aside><p>Side note</p></aside>' },
  { id: 'nav', label: 'Nav', group: 'Layout', html: '<nav aria-label="Links"><a href="#">Link</a> <a href="#">Link</a></nav>' },
  { id: 'h1', label: 'Heading 1', group: 'Text', html: '<h1>Heading</h1>' },
  { id: 'h2', label: 'Heading 2', group: 'Text', html: '<h2>Heading</h2>' },
  { id: 'h3', label: 'Heading 3', group: 'Text', html: '<h3>Heading</h3>' },
  { id: 'h4', label: 'Heading 4', group: 'Text', html: '<h4>Heading</h4>' },
  { id: 'p', label: 'Paragraph', group: 'Text', html: '<p>Write your text here.</p>' },
  { id: 'span', label: 'Span', group: 'Text', html: '<span>Text</span>' },
  { id: 'quote', label: 'Quote', group: 'Text', html: '<blockquote><p>“A memorable quote.”</p><footer>— Someone</footer></blockquote>' },
  { id: 'ul', label: 'List', group: 'Text', html: '<ul><li>One</li><li>Two</li><li>Three</li></ul>' },
  { id: 'ol', label: 'Numbered list', group: 'Text', html: '<ol><li>One</li><li>Two</li><li>Three</li></ol>' },
  { id: 'hr', label: 'Divider', group: 'Text', html: '<hr>' },
  { id: 'a', label: 'Link', group: 'Media & links', html: '<a href="#">Link text</a>' },
  { id: 'button', label: 'Button', group: 'Media & links', html: '<a href="#" class="inline-flex items-center rounded-full bg-gold px-6 py-3 font-semibold text-forest">Button</a>' },
  { id: 'img', label: 'Image', group: 'Media & links', html: '<img src="https://images.unsplash.com/photo-1506929562872-bb421503ef21?auto=format&fit=crop&w=1200&q=80" alt="Describe the image" loading="lazy" width="1200" height="800" class="h-auto w-full rounded-2xl">' },
  { id: 'video', label: 'Video', group: 'Media & links', html: '<video controls preload="none" class="w-full rounded-2xl"></video>' },
  { id: 'iframe', label: 'Embed', group: 'Media & links', html: '<iframe src="about:blank" title="Embedded content" loading="lazy" class="aspect-video w-full rounded-2xl"></iframe>' },
  { id: 'form', label: 'Form', group: 'Forms', html: '<form class="grid gap-3"><label>Name<input type="text" name="name" class="w-full rounded border px-3 py-2"></label><button type="submit">Send</button></form>' },
  { id: 'input', label: 'Input', group: 'Forms', html: '<input type="text" placeholder="Type here" class="rounded border px-3 py-2">' },
  { id: 'textarea', label: 'Textarea', group: 'Forms', html: '<textarea rows="4" placeholder="Message" class="w-full rounded border px-3 py-2"></textarea>' },
  { id: 'table', label: 'Table', group: 'Forms', html: '<table><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td>One</td><td>1</td></tr></tbody></table>' },
];

/* ---------- html helpers ---------- */

/** Markup of an element without the editor's own attributes, ready to be inserted elsewhere. */
export const cleanHtml = (html: string): string =>
  html
    .replace(/\sdata-ve-[a-z]+(="[^"]*")?/g, '')
    .replace(/\scontenteditable(="[^"]*")?/g, '')
    .replace(/\sstyle="display: contents;"/g, '');

/** Tags that cannot (sensibly) hold other elements: a drop on them means "before" or "after". */
const LEAF_TAGS = new Set([
  'img', 'input', 'br', 'hr', 'video', 'audio', 'iframe', 'textarea', 'select', 'svg', 'canvas', 'source', 'path',
  'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'a', 'button', 'label', 'li', 'strong', 'em', 'b', 'i', 'small', 'td', 'th',
]);

export const canContain = (el: Element): boolean => !LEAF_TAGS.has(el.localName);

/* ---------- drop target ---------- */

export type Drop = { anchor: HTMLElement; pos: Pos };

/** Where a drop at the pointer lands relative to `el`: before/after near its edges, inside in the middle. */
export const dropFor = (el: HTMLElement, x: number, y: number): Drop => {
  const rect = el.getBoundingClientRect();
  const win = el.ownerDocument.defaultView;
  const parent = el.parentElement;
  const parentStyle = parent && win ? win.getComputedStyle(parent) : null;
  const horizontal =
    !!parentStyle &&
    (parentStyle.display.includes('flex') ? !parentStyle.flexDirection.startsWith('column') : false);

  const ratio = horizontal ? (x - rect.left) / Math.max(rect.width, 1) : (y - rect.top) / Math.max(rect.height, 1);

  if (canContain(el) && ratio > 0.25 && ratio < 0.75) return { anchor: el, pos: 'append' };
  return { anchor: el, pos: ratio < 0.5 ? 'before' : 'after' };
};

/** Element under the pointer inside the preview (coordinates relative to the preview's viewport). */
export const hitTest = (doc: Document, x: number, y: number, exclude?: Element | null): HTMLElement | null => {
  let el = pickElement(doc.elementFromPoint(x, y));
  while (el && exclude && (el === exclude || exclude.contains(el))) el = el.parentElement;
  return el && el.localName !== 'html' ? (el as HTMLElement) : null;
};

/* ---------- building ops ---------- */

export const scopeFor = (anchor: Element): Scope => (anchor.closest('header, footer') ? 'site' : 'page');

export const makeInsertOp = (anchorSel: string, pos: Pos, html: string, label: string, scope: Scope, page: string, comp?: string, exec?: boolean): Op => ({
  id: newId(),
  kind: 'insert',
  sel: anchorSel,
  pos,
  html: cleanHtml(html),
  label,
  scope,
  page,
  ...(comp ? { comp } : {}),
  ...(exec ? { exec: true } : {}),
});

export const makeMoveOp = (sel: string, to: string, pos: Pos, label: string, scope: Scope, page: string): Op => ({
  id: newId(),
  kind: 'move',
  sel,
  to,
  pos,
  label,
  scope,
  page,
});

export const makeRemoveOp = (sel: string, label: string, scope: Scope, page: string): Op => ({
  id: newId(),
  kind: 'remove',
  sel,
  label,
  scope,
  page,
});

export const makeTagOp = (sel: string, tag: string, label: string, scope: Scope, page: string): Op => ({
  id: newId(),
  kind: 'tag',
  sel,
  tag,
  label,
  scope,
  page,
});

/**
 * After an element gets its own id (moved / re-tagged), edits written against its old path must follow it,
 * otherwise they would keep pointing at the hidden placeholder left behind.
 */
export const rebaseEdits = (edits: Edit[], from: string, to: string): Edit[] =>
  edits.map((edit) => {
    if (edit.sel === from) return { ...edit, sel: to };
    if (edit.sel.startsWith(`${from} > `)) return { ...edit, sel: to + edit.sel.slice(from.length) };
    return edit;
  });

export const withOp = (doc: VisualDoc, op: Op, rebase?: { from: string; to: string }): VisualDoc => ({
  ...doc,
  ops: [...(doc.ops ?? []), op],
  edits: rebase ? rebaseEdits(doc.edits, rebase.from, rebase.to) : doc.edits,
});

/** Selector of the element an op creates or relocates, so it can be selected right after. */
export const anchorFor = (op: Op): string => `[data-ve-id="${op.id}"]`;
