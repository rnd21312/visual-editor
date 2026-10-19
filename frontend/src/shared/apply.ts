// Replays content edits (text / HTML / attributes) and structural ops (insert / move / remove / re-tag) on a
// document. Used by the public runtime and by the editor's live preview. Idempotent: ops already applied (also
// by the server) are marked with data-ve-ops and skipped; content edits remember the original so they can be
// reverted when the editor changes them.
import type { ContentEdit, RuntimeOp } from './types';

const PROTECTED = new Set(['script', 'style', 'base', 'meta', 'link', 'noscript', 'template', 'iframe', 'object', 'embed', 'head', 'html', 'title']);

const query = (doc: Document, sel: string): Element | null => {
  try {
    return doc.querySelector(sel);
  } catch {
    return null;
  }
};

const queryAll = (doc: Document, sel: string): Element[] => {
  try {
    return Array.from(doc.querySelectorAll(sel));
  } catch {
    return [];
  }
};

/** Frameworks that later remove a node we moved should not crash the page. */
const tolerate = (win: Window & typeof globalThis): void => {
  const proto = win.Node.prototype as Node & { __sveTolerant?: boolean };
  if (proto.__sveTolerant) return;
  proto.__sveTolerant = true;
  const remove = proto.removeChild;
  const insert = proto.insertBefore;
  proto.removeChild = function <T extends Node>(this: Node, child: T): T {
    return child.parentNode !== this ? child : (remove.call(this, child) as T);
  };
  proto.insertBefore = function <T extends Node>(this: Node, node: T, ref: Node | null): T {
    return ref && ref.parentNode !== this ? node : (insert.call(this, node, ref) as T);
  };
};

type Original = { html?: string; attrs: Map<string, string | null> };

export type Applier = {
  set: (edits: ContentEdit[], ops: RuntimeOp[]) => 'ok' | 'reload';
  flush: () => void;
  disconnect: () => void;
};

export const createApplier = (doc: Document, onApplied?: () => void): Applier => {
  const win = doc.defaultView as (Window & typeof globalThis) | null;
  const originals = new Map<Element, Original>();
  const setHtml = new WeakMap<Element, string>();
  let edits: ContentEdit[] = [];
  let ops: RuntimeOp[] = [];
  let observer: MutationObserver | null = null;
  let frame = 0;

  const original = (el: Element): Original => {
    let o = originals.get(el);
    if (!o) {
      o = { attrs: new Map() };
      originals.set(el, o);
    }
    return o;
  };

  const applied = (id: string) => query(doc, `[data-ve-ops~="${id}"]`) !== null;

  const mark = (el: Element, id: string, setId = true) => {
    el.setAttribute('data-ve-ops', `${el.getAttribute('data-ve-ops') ?? ''} ${id}`.trim());
    if (setId && !el.hasAttribute('data-ve-id')) el.setAttribute('data-ve-id', id);
  };

  const placeholder = (id: string) => {
    const t = doc.createElement('template');
    t.setAttribute('data-ve-ph', id);
    return t;
  };

  const place = (node: Element, target: Element, pos: string | undefined) => {
    if (pos === 'before') target.before(node);
    else if (pos === 'prepend') target.prepend(node);
    else if (pos === 'append') target.append(node);
    else target.after(node);
  };

  const fragment = (html: string): Element => {
    const t = doc.createElement('template');
    t.innerHTML = html;
    const nodes = Array.from(t.content.childNodes).filter((n) => n.nodeType === 1 || (n.nodeValue ?? '').trim() !== '');
    if (nodes.length === 1 && nodes[0].nodeType === 1) return nodes[0] as Element;
    const box = doc.createElement('div');
    box.style.display = 'contents';
    box.append(...Array.from(t.content.childNodes));
    return box;
  };

  const runScripts = (root: Element) => {
    const list = root.localName === 'script' ? [root] : Array.from(root.querySelectorAll('script'));
    for (const old of list) {
      if (/ld\+json|importmap/i.test(old.getAttribute('type') ?? '')) continue;
      const s = doc.createElement('script');
      for (const a of Array.from(old.attributes)) s.setAttribute(a.name, a.value);
      s.textContent = old.textContent;
      old.replaceWith(s);
    }
  };

  const applyOps = () => {
    for (const op of ops) {
      if (applied(op.id)) continue;
      if (op.kind === 'insert') {
        const target = query(doc, op.sel);
        if (!target) continue;
        const node = fragment(op.html ?? '');
        mark(node, op.id);
        place(node, target, op.pos);
        if (op.exec) runScripts(node);
      } else if (op.kind === 'move') {
        const el = query(doc, op.sel);
        const to = op.to ? query(doc, op.to) : null;
        if (!el || !to || el === to || el.contains(to)) continue;
        if (el.hasAttribute('data-ve-id')) el.remove();
        else el.replaceWith(placeholder(op.id));
        mark(el, op.id);
        place(el, to, op.pos);
      } else if (op.kind === 'remove') {
        const el = query(doc, op.sel);
        if (!el) continue;
        if (el.hasAttribute('data-ve-id')) el.remove();
        else {
          const ph = placeholder(op.id);
          mark(ph, op.id, false);
          el.replaceWith(ph);
        }
      } else if (op.kind === 'tag') {
        const el = query(doc, op.sel);
        if (!el || !op.tag || !/^[a-z][a-z0-9-]*$/.test(op.tag) || el.localName === op.tag) continue;
        const next = doc.createElement(op.tag);
        for (const a of Array.from(el.attributes)) if (a.name !== 'data-ve-ops') next.setAttribute(a.name, a.value);
        next.append(...Array.from(el.childNodes));
        if (el.hasAttribute('data-ve-id')) el.replaceWith(next);
        else el.replaceWith(placeholder(op.id), next);
        mark(next, op.id);
      }
    }
  };

  type Want = { html?: string; text?: string; attrs: Record<string, string | null>; trusted: boolean };

  const wanted = (): Map<Element, Want> => {
    const map = new Map<Element, Want>();
    for (const e of edits) {
      for (const el of queryAll(doc, e.sel)) {
        const w = map.get(el) ?? { attrs: {}, trusted: false };
        if (e.html !== undefined) {
          w.html = e.html;
          delete w.text;
        } else if (e.text !== undefined) {
          w.text = e.text;
          delete w.html;
        }
        Object.assign(w.attrs, e.attrs ?? {});
        w.trusted = w.trusted || !!e.t;
        map.set(el, w);
      }
    }
    return map;
  };

  const revert = (want: Map<Element, Want>) => {
    for (const [el, o] of originals) {
      const w = want.get(el);
      if (o.html !== undefined && w?.html === undefined && w?.text === undefined) {
        el.innerHTML = o.html;
        delete o.html;
        setHtml.delete(el);
      }
      for (const [name, value] of Array.from(o.attrs)) {
        if (w && name in w.attrs) continue;
        if (value === null) el.removeAttribute(name);
        else el.setAttribute(name, value);
        o.attrs.delete(name);
      }
    }
  };

  const applyContent = () => {
    const want = wanted();
    revert(want);
    for (const [el, w] of want) {
      if (!w.trusted && PROTECTED.has(el.localName)) continue;
      if (w.html !== undefined) {
        if (setHtml.get(el) !== w.html) {
          const o = original(el);
          o.html ??= el.innerHTML;
          el.innerHTML = w.html;
          setHtml.set(el, w.html);
        }
      } else if (w.text !== undefined) {
        const only = el.childNodes.length === 1 && el.firstChild?.nodeType === 3 ? el.firstChild : null;
        if (only) {
          if (only.nodeValue !== w.text) {
            const o = original(el);
            o.html ??= el.innerHTML;
            only.nodeValue = w.text;
          }
        } else if (el.textContent !== w.text) {
          const o = original(el);
          o.html ??= el.innerHTML;
          el.textContent = w.text;
        }
      }
      for (const [name, value] of Object.entries(w.attrs)) {
        const o = original(el);
        if (!o.attrs.has(name)) o.attrs.set(name, el.getAttribute(name));
        if (value === null) {
          if (el.hasAttribute(name)) el.removeAttribute(name);
        } else if (el.getAttribute(name) !== value) el.setAttribute(name, value);
      }
    }
  };

  const run = () => {
    observer?.disconnect();
    applyOps();
    applyContent();
    observe();
    onApplied?.();
  };

  const observe = () => {
    if (!win || (edits.length === 0 && ops.length === 0)) return;
    const root = doc.body ?? doc.documentElement;
    const names = new Set<string>();
    edits.forEach((e) => Object.keys(e.attrs ?? {}).forEach((n) => names.add(n)));
    observer ??= new win.MutationObserver(() => {
      if (frame) return;
      frame = win.requestAnimationFrame(() => {
        frame = 0;
        run();
      });
    });
    observer.observe(root, { childList: true, subtree: true, characterData: true, ...(names.size ? { attributes: true, attributeFilter: Array.from(names) } : {}) });
  };

  return {
    set(nextEdits, nextOps) {
      // Ops are sequential: if earlier ones changed, a clean page is needed.
      const prefix = ops.every((op, i) => JSON.stringify(op) === JSON.stringify(nextOps[i]));
      if (!prefix) return 'reload';
      edits = nextEdits;
      ops = nextOps;
      if (win && ops.some((o) => o.kind === 'move' || o.kind === 'remove' || o.kind === 'tag')) tolerate(win);
      run();
      return 'ok';
    },
    flush: run,
    disconnect() {
      observer?.disconnect();
      if (frame && win) win.cancelAnimationFrame(frame);
    },
  };
};
