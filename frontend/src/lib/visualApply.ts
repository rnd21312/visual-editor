/**
 * Applies visual-editor content edits (text, HTML, attributes) to a document.
 *
 * Used by the public site (saved edits) and by the editor (live preview). The page is React-rendered,
 * so a MutationObserver re-asserts the edits whenever React re-creates the elements.
 */

import type { Op, Pos } from './visualCss';

export type ContentEdit = {
  sel: string;
  text?: string;
  html?: string;
  attrs?: Record<string, string | null>;
};

type Wanted = { text?: string; html?: string; attrs: Record<string, string | null> };
type Original = { html?: string; attrs: Map<string, string | null> };

const PATCH_FLAG = '__stzTolerantDom';

/**
 * Replacing the children of a React-owned element can make a later React update call removeChild on
 * a node that is no longer there, which would blank the page. Make those two calls forgiving.
 */
const makeDomTolerant = (win: Window & typeof globalThis): void => {
  const proto = win.Node.prototype as Node & Record<string, unknown>;
  if (proto[PATCH_FLAG]) return;
  proto[PATCH_FLAG] = true;

  const removeChild = proto.removeChild;
  const insertBefore = proto.insertBefore;

  proto.removeChild = function <T extends Node>(this: Node, child: T): T {
    if (child.parentNode !== this) return child;
    return removeChild.call(this, child) as T;
  };
  proto.insertBefore = function <T extends Node>(this: Node, node: T, ref: Node | null): T {
    if (ref && ref.parentNode !== this) return node;
    return insertBefore.call(this, node, ref) as T;
  };
};

const query = (doc: Document, sel: string): Element[] => {
  try {
    return Array.from(doc.querySelectorAll(sel));
  } catch {
    return [];
  }
};

export const createApplier = (doc: Document) => {
  const win = doc.defaultView as (Window & typeof globalThis) | null;
  const originals = new Map<Element, Original>();
  /** HTML last written to an element: it is not written again until the edit or the element changes. */
  const appliedHtml = new WeakMap<Element, string>();
  let edits: ContentEdit[] = [];
  let observer: MutationObserver | null = null;

  const original = (el: Element): Original => {
    let known = originals.get(el);
    if (!known) {
      known = { attrs: new Map() };
      originals.set(el, known);
    }
    return known;
  };

  const setHtml = (el: Element, html: string): void => {
    if (appliedHtml.get(el) === html) return;
    original(el).html ??= el.innerHTML;
    el.innerHTML = html;
    appliedHtml.set(el, html);
  };

  const setText = (el: Element, text: string): void => {
    const only = el.childNodes.length === 1 && el.firstChild?.nodeType === Node.TEXT_NODE ? el.firstChild : null;

    if (only) {
      if (only.nodeValue === text) return;
      original(el).html ??= el.innerHTML;
      only.nodeValue = text; // Same text node, so React keeps its reference.
    } else if (el.textContent !== text) {
      original(el).html ??= el.innerHTML;
      el.textContent = text;
    }
  };

  const setAttrs = (el: Element, attrs: Record<string, string | null>): void => {
    for (const [name, value] of Object.entries(attrs)) {
      const known = original(el);
      if (!known.attrs.has(name)) known.attrs.set(name, el.getAttribute(name));
      if (value === null) {
        if (el.hasAttribute(name)) el.removeAttribute(name);
      } else if (el.getAttribute(name) !== value) {
        el.setAttribute(name, value);
      }
    }
  };

  /* ---------- structural ops (insert / move / remove / retag) ---------- */

  let ops: Op[] = [];

  const first = (sel: string): Element | null => {
    try {
      return doc.querySelector(sel);
    } catch {
      return null;
    }
  };

  /** Every op leaves its id in data-ve-ops on the element it produced, which makes replays idempotent. */
  const applied = (id: string): boolean => first(`[data-ve-ops~="${id}"]`) !== null;

  const mark = (el: Element, id: string, identity = true): void => {
    el.setAttribute('data-ve-ops', `${el.getAttribute('data-ve-ops') ?? ''} ${id}`.trim());
    if (identity && !el.hasAttribute('data-ve-id')) el.setAttribute('data-ve-id', id);
  };

  /**
   * Inert marker left where an element used to be (moved, removed or re-tagged), so its siblings keep their
   * original positions. A <template> renders nothing and carries no content for crawlers.
   */
  const placeholder = (id: string): Element => {
    const twin = doc.createElement('template');
    twin.setAttribute('data-ve-ph', id);
    return twin;
  };

  const place = (node: Node, anchor: Element, pos: Pos): void => {
    if (pos === 'before') anchor.before(node);
    else if (pos === 'after') anchor.after(node);
    else if (pos === 'prepend') anchor.prepend(node);
    else anchor.append(node);
  };

  /** Markup → one element (several roots are wrapped in a display:contents box). */
  const build = (html: string): Element => {
    const holder = doc.createElement('template');
    holder.innerHTML = html;
    const nodes = Array.from(holder.content.childNodes).filter(
      (node) => node.nodeType === Node.ELEMENT_NODE || (node.nodeValue ?? '').trim() !== '',
    );
    const only = nodes[0];
    if (nodes.length === 1 && only?.nodeType === Node.ELEMENT_NODE) return only as Element;

    const wrap = doc.createElement('div');
    wrap.style.display = 'contents';
    wrap.append(...Array.from(holder.content.childNodes));
    return wrap;
  };

  /** Markup parsed with innerHTML never runs its scripts; embed snippets need to, so recreate them. */
  const activate = (root: Element): void => {
    const scripts = root.localName === 'script' ? [root] : Array.from(root.querySelectorAll('script'));
    for (const old of scripts) {
      if (/ld\+json|importmap/i.test(old.getAttribute('type') ?? '')) continue; // data, not code
      const fresh = doc.createElement('script');
      for (const attr of Array.from(old.attributes)) fresh.setAttribute(attr.name, attr.value);
      fresh.textContent = old.textContent;
      old.replaceWith(fresh);
    }
  };

  const applyOps = (): void => {
    for (const op of ops) {
      if (applied(op.id)) continue;

      if (op.kind === 'insert') {
        const anchor = first(op.sel);
        if (!anchor) continue;
        const el = build(op.html);
        mark(el, op.id);
        place(el, anchor, op.pos);
        if (op.exec) activate(el);
      } else if (op.kind === 'move') {
        const el = first(op.sel);
        const to = first(op.to);
        if (!el || !to || el === to || el.contains(to)) continue;
        if (!el.hasAttribute('data-ve-id')) el.replaceWith(placeholder(op.id));
        else el.remove();
        mark(el, op.id);
        place(el, to, op.pos);
      } else if (op.kind === 'remove') {
        const el = first(op.sel);
        if (!el) continue;
        if (el.hasAttribute('data-ve-id')) {
          el.remove();
        } else {
          const twin = placeholder(op.id);
          mark(twin, op.id, false);
          el.replaceWith(twin);
        }
      } else {
        const el = first(op.sel);
        if (!el || !/^[a-z][a-z0-9-]*$/.test(op.tag) || el.localName === op.tag) continue;
        const next = doc.createElement(op.tag);
        for (const attr of Array.from(el.attributes)) {
          if (attr.name !== 'data-ve-ops') next.setAttribute(attr.name, attr.value);
        }
        next.append(...Array.from(el.childNodes));
        if (el.hasAttribute('data-ve-id')) {
          el.replaceWith(next);
        } else {
          el.replaceWith(placeholder(op.id), next);
        }
        mark(next, op.id);
      }
    }
  };

  /** What every matching element should end up with (later edits win). */
  const collect = (): Map<Element, Wanted> => {
    const wanted = new Map<Element, Wanted>();
    for (const edit of edits) {
      for (const el of query(doc, edit.sel)) {
        const entry = wanted.get(el) ?? { attrs: {} };
        if (edit.html !== undefined) {
          entry.html = edit.html;
          delete entry.text;
        } else if (edit.text !== undefined) {
          entry.text = edit.text;
          delete entry.html;
        }
        Object.assign(entry.attrs, edit.attrs ?? {});
        wanted.set(el, entry);
      }
    }
    return wanted;
  };

  /** Undo whatever an edit no longer asks for. */
  const restore = (wanted: Map<Element, Wanted>): void => {
    for (const [el, known] of originals) {
      const want = wanted.get(el);
      if (known.html !== undefined && want?.html === undefined && want?.text === undefined) {
        el.innerHTML = known.html;
        delete known.html;
        appliedHtml.delete(el);
      }
      for (const [name, value] of Array.from(known.attrs)) {
        if (want && name in want.attrs) continue;
        if (value === null) el.removeAttribute(name);
        else el.setAttribute(name, value);
        known.attrs.delete(name);
      }
    }
  };

  // Runs straight from the observer callback (a microtask, before the browser paints), so there is no
  // flash of the original text. Our own writes happen while the observer is disconnected.
  const run = (): void => {
    observer?.disconnect();

    applyOps();
    restore(collect());

    // HTML first: it re-creates the element's children, which text and attribute edits may target.
    for (const [el, want] of collect()) {
      if (want.html !== undefined) setHtml(el, want.html);
    }
    for (const [el, want] of collect()) {
      if (want.text !== undefined) setText(el, want.text);
      setAttrs(el, want.attrs);
    }

    connect();
  };

  const connect = (): void => {
    if (!win || (edits.length === 0 && ops.length === 0) || !doc.body) return;
    const names = new Set<string>();
    edits.forEach((edit) => Object.keys(edit.attrs ?? {}).forEach((name) => names.add(name)));

    observer ??= new win.MutationObserver(run);
    observer.observe(doc.body, {
      childList: true,
      subtree: true,
      characterData: true,
      ...(names.size > 0 ? { attributes: true, attributeFilter: Array.from(names) } : {}),
    });
  };

  return {
    /**
     * Applies content edits and structural ops. Returns "reload" when the ops are no longer an extension of
     * what was applied (undo, a removed op): the page has to be rebuilt from scratch for that.
     */
    set(next: ContentEdit[], nextOps: Op[] = []): 'ok' | 'reload' {
      const extends_ = ops.every((op, i) => JSON.stringify(op) === JSON.stringify(nextOps[i]));
      if (!extends_) return 'reload';

      edits = next;
      ops = nextOps;
      if (win && (nextOps.length > 0 || next.some((edit) => edit.html !== undefined || edit.text !== undefined))) {
        makeDomTolerant(win);
      }
      run();
      return 'ok';
    },
    disconnect(): void {
      observer?.disconnect();
    },
  };
};

type PublicPayload = { edits?: ContentEdit[]; ops?: Op[] };

/** Public site: replay the content edits the server printed for this page. */
export const initVisualEdits = (): void => {
  const node = document.getElementById('sve-data');
  if (!node?.textContent) return;

  try {
    const payload = JSON.parse(node.textContent) as PublicPayload;
    if (payload.edits?.length || payload.ops?.length) createApplier(document).set(payload.edits ?? [], payload.ops ?? []);
  } catch {
    // Bad JSON must never break the page.
  }
};
