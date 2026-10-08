/** Selector helpers: stable CSS paths for elements, plus the readable names the panels show. */

const SKIP_TAGS = new Set(['script', 'style', 'link', 'meta', 'noscript', 'template', 'head', 'title', 'base']);

/** Parts of the page that are not the site's design (admin bar, splash screen, editor injections). */
const EXCLUDED = '#wpadminbar';

/** Nodes the editor itself put into the preview. */
export const isEditorNode = (el: Element): boolean => el.hasAttribute('data-ve-editor');

/** Anything the Elements list should show: every tag of the page, <head> included. */
export const isTreeNode = (el: Element): boolean =>
  !isEditorNode(el) && !isPlaceholder(el) && !el.closest(EXCLUDED);

/** Empty hidden twins the page keeps where an element was moved, removed or re-tagged. */
export const isPlaceholder = (el: Element): boolean => el.hasAttribute('data-ve-ph');

export const isSelectable = (el: Element | null): el is HTMLElement =>
  !!el && !SKIP_TAGS.has(el.localName) && !isPlaceholder(el) && !el.closest(EXCLUDED);

/** Nearest selectable element at or above `node`. */
export const pickElement = (node: EventTarget | null): HTMLElement | null => {
  // No `instanceof`: the preview is another window, so its elements are not this window's Element.
  const start = node as Node | null;
  let el: Element | null = start?.nodeType === Node.ELEMENT_NODE ? (start as Element) : (start?.parentElement ?? null);
  while (el && !isSelectable(el)) el = el.parentElement;
  return el as HTMLElement | null;
};

const stableId = (el: Element): boolean => {
  const id = el.id;
  if (!id || !/^[A-Za-z][\w-]*$/.test(id) || /\d{3,}/.test(id) || /^(radix|headlessui|react)/i.test(id)) return false;
  return el.ownerDocument.querySelectorAll(`#${CSS.escape(id)}`).length === 1;
};

/**
 * Structural path anchored at the nearest stable id, landmark, or element the editor inserted/moved
 * (data-ve-id), e.g. `#main > section:nth-child(2 of :not([data-ve-id])) > h2`.
 *
 * Positions ignore inserted/moved elements and count the hidden placeholders, so a path always describes the
 * page as it was before any structural edit — adding or dragging things never invalidates older edits.
 */
export const pathSelector = (el: Element): string => {
  const parts: string[] = [];
  let node: Element | null = el;

  while (node) {
    const name = node.localName;
    const ve = node.getAttribute('data-ve-id');
    if (ve && /^[\w-]+$/.test(ve)) {
      parts.unshift(`[data-ve-id="${ve}"]`);
      break;
    }
    if (name === 'html' || name === 'body') {
      parts.unshift(name);
      break;
    }
    if (stableId(node)) {
      parts.unshift(`#${CSS.escape(node.id)}`);
      break;
    }
    // A lone <header>/<footer> is the same element on every page, so anchor there: site-wide edits made
    // on one page then match on all of them, whatever the page body looks like.
    if ((name === 'header' || name === 'footer') && node.ownerDocument.querySelectorAll(name).length === 1) {
      parts.unshift(name);
      break;
    }
    const parent: Element | null = node.parentElement;
    if (!parent) break;
    const original = Array.from(parent.children).filter((child) => !child.hasAttribute('data-ve-id'));
    parts.unshift(`${name}:nth-child(${original.indexOf(node) + 1} of :not([data-ve-id]))`);
    node = parent;
  }

  return parts.join(' > ');
};

const unique = <T>(items: T[]): T[] => Array.from(new Set(items));

/** Alternative selectors a rule could use to hit more than this one element. */
export const selectorChoices = (el: Element): string[] => {
  const name = el.localName;
  const classes = Array.from(el.classList);
  const escaped = classes.map((cls) => `.${CSS.escape(cls)}`);

  return unique([
    pathSelector(el),
    ...(classes.length > 0 ? [name + escaped.join('')] : []),
    ...escaped.slice(0, 8),
    name,
  ]);
};

export const matchCount = (doc: Document, sel: string): number => {
  try {
    return doc.querySelectorAll(sel).length;
  } catch {
    return -1; // invalid selector
  }
};

export const safeMatches = (el: Element, sel: string): boolean => {
  try {
    return el.matches(sel);
  } catch {
    return false;
  }
};

/** `div#hero.relative.flex` — short name for panels and rule labels. */
export const describe = (el: Element, maxClasses = 2): string => {
  const id = el.id && stableId(el) ? `#${el.id}` : '';
  const classes = Array.from(el.classList)
    .filter((cls) => !cls.includes(':') && !cls.includes('['))
    .slice(0, maxClasses)
    .map((cls) => `.${cls}`)
    .join('');
  return `${el.localName}${id}${classes}`;
};

export const hasElementChildren = (el: Element): boolean => el.children.length > 0;

/** Direct text of an element, used as a hint in the tree. */
export const textSnippet = (el: Element, max = 40): string => {
  const text = Array.from(el.childNodes)
    .filter((node) => node.nodeType === Node.TEXT_NODE)
    .map((node) => node.nodeValue ?? '')
    .join(' ')
    .replace(/\s+/g, ' ')
    .trim();
  return text.length > max ? `${text.slice(0, max)}…` : text;
};

/**
 * Whether the element is actually displayed at the current preview width: themes ship alternative headers,
 * menus and sections that are hidden by display:none or media queries and must not clutter the editor.
 */
export const isRendered = (el: Element): boolean => {
  const win = el.ownerDocument.defaultView;
  if (!win) return true;
  const style = win.getComputedStyle(el);
  if (style.display === 'contents') return true; // no box of its own, its children decide
  if (style.display === 'none') return false;
  return el.getClientRects().length > 0 && style.visibility !== 'hidden';
};

/** Class names of an element, without the editor's own. */
export const classesOf = (el: Element): string[] => Array.from(el.classList);

/** `.hero-title` with every character that needs it escaped. */
export const classSelector = (name: string): string => `.${CSS.escape(name)}`;
