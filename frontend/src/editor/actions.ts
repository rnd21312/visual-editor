// Element operations, whatever the element belongs to:
//  - builder layouts (page/template/part source): precise source edits by data-sid, then the preview refreshes;
//  - components and inserted elements: the same on their markup;
//  - theme markup: structural ops (insert / move / remove / re-tag) replayed on the page.
import { applySource, ensureIds, innerOf, isPlainText, outerOf, stripIds, type SourceAction, type SourceArgs } from '../shared/source';
import { selectorFor, describe } from '../shared/selector';
import type { Op } from '../shared/types';
import { findAgain, frameDoc, ownerOf, invalidateComponents } from './frame';
import { t } from './i18n';
import { flushWorking, useStore, type Owner, type Selection } from './store';

const S = () => useStore.getState();

export const selectionFor = (el: Element): Selection => {
  const sid = el.getAttribute('data-sid');
  return { el, sel: sid ? `[data-sid="${sid}"]` : selectorFor(el), sid, owner: ownerOf(el), label: describe(el) };
};

/** Default scope for styling the element (layout elements are unique, so "site" is exact for templates/components). */
export const defaultScope = (owner: Owner): 'page' | 'site' | null => {
  const s = S();
  if (owner.kind === 'component') return 'site';
  if (owner.kind === 'post') return s.ctx?.builder.post?.id === owner.id ? 'page' : 'site';
  if (owner.kind === 'op') {
    const op = s.doc.ops.find((o) => o.id === owner.id);
    return op?.scope === 'site' ? 'site' : null;
  }
  return null;
};

export const select = (el: Element | null): void => {
  if (!el) {
    S().select(null);
    return;
  }
  const sel = selectionFor(el);
  const scope = defaultScope(sel.owner);
  useStore.setState(scope ? { scope } : {});
  S().select(sel);
};

/** Re-selects the current element after a reload of the preview. */
export const reselect = (): void => {
  const cur = S().selected;
  if (!cur) return;
  const el = findAgain(cur.sel);
  if (el) S().select({ ...selectionFor(el), owner: cur.owner });
  else S().select(null);
};

const refreshAfterSource = async (newSid?: string | false) => {
  await flushWorking();
  // Sources are saved as working copies (debounced 400ms): wait, then reload the preview.
  window.setTimeout(() => {
    if (newSid) useStore.setState({ selected: { ...(S().selected as Selection), sel: `[data-sid="${newSid}"]`, sid: newSid } as Selection });
    S().reload();
  }, 480);
};

/** Edits the markup that owns an element (layout source, component or inserted element). */
const editOwner = (owner: Owner, sid: string, action: SourceAction, args: SourceArgs = {}): string | undefined | false => {
  const s = S();
  if (owner.kind === 'post') {
    const r = s.editSource(owner.id, action, sid, args);
    if (r !== false) void refreshAfterSource(r);
    return r;
  }
  if (owner.kind === 'component') {
    const c = s.doc.components.find((x) => x.id === owner.id);
    if (!c) return false;
    const r = applySource(c.html, sid, action, args);
    if (!r) return false;
    s.setDoc((d) => {
      const comp = d.components.find((x) => x.id === owner.id);
      if (comp) comp.html = r.source;
    });
    invalidateComponents();
    void refreshAfterSource(r.newSid);
    return r.newSid;
  }
  if (owner.kind === 'op') {
    const op = s.doc.ops.find((o) => o.id === owner.id);
    if (!op?.html) return false;
    const r = applySource(op.html, sid, action, args);
    if (!r) return false;
    s.setDoc((d) => {
      const o = d.ops.find((x) => x.id === owner.id);
      if (o) {
        if (r.source.trim() === '') d.ops = d.ops.filter((x) => x.id !== owner.id);
        else o.html = r.source;
      }
    });
    if (r.newSid) useStore.setState({ selected: { ...(S().selected as Selection), sel: `[data-sid="${r.newSid}"]`, sid: r.newSid } as Selection });
    return r.newSid;
  }
  return false;
};

const ensureSid = (sel: Selection): boolean => {
  if (sel.sid) return true;
  S().toast(t('This element has no builder id.'), 'error');
  return false;
};

/* ------------------------------------------------------------------ actions */

export const removeSelected = (): void => {
  const sel = S().selected;
  if (!sel) return;
  if (sel.owner.kind !== 'theme' && sel.sid) {
    // A whole inserted element is removed by dropping its op.
    if (sel.owner.kind === 'op' && sel.el.getAttribute('data-ve-id') === sel.owner.id) {
      const id = sel.owner.id;
      S().setDoc((d) => {
        d.ops = d.ops.filter((o) => o.id !== id);
      });
      S().select(null);
      S().reload();
      return;
    }
    editOwner(sel.owner, sel.sid, 'remove');
    sel.el.remove();
    S().select(null);
    return;
  }
  if (sel.el.hasAttribute('data-ve-id')) {
    const id = sel.el.getAttribute('data-ve-id');
    S().setDoc((d) => {
      d.ops = d.ops.filter((o) => o.id !== id);
    });
    S().select(null);
    S().reload();
    return;
  }
  S().addOp({ kind: 'remove', sel: sel.sel, label: t('Remove') + ' ' + sel.label });
  S().select(null);
};

export const duplicateSelected = (): void => {
  const sel = S().selected;
  if (!sel) return;
  if (sel.owner.kind !== 'theme' && sel.sid) {
    editOwner(sel.owner, sel.sid, 'duplicate');
    return;
  }
  const html = ensureIds(stripIds(sel.el.outerHTML.replace(/\sdata-ve-(id|ops)="[^"]*"/g, '')));
  S().addOp({ kind: 'insert', sel: sel.sel, pos: 'after', html, label: t('Copy of') + ' ' + sel.label });
};

/** Moves the selected element before/after/into another element of the preview. */
export const moveSelected = (to: Element, pos: 'before' | 'after' | 'append'): void => {
  const sel = S().selected;
  if (!sel || to === sel.el || sel.el.contains(to)) return;
  const target = selectionFor(to);
  if (sel.owner.kind === 'post' && target.owner.kind === 'post' && sel.owner.id === target.owner.id && sel.sid && target.sid) {
    editOwner(sel.owner, sel.sid, pos === 'before' ? 'move_before' : pos === 'after' ? 'move_after' : 'move_into', { target: target.sid });
    return;
  }
  if ((sel.owner.kind === 'component' || sel.owner.kind === 'op') && sel.owner.kind === target.owner.kind && sel.owner.id === (target.owner as { id: string }).id && sel.sid && target.sid) {
    editOwner(sel.owner, sel.sid, pos === 'before' ? 'move_before' : pos === 'after' ? 'move_after' : 'move_into', { target: target.sid });
    return;
  }
  if (sel.owner.kind === 'post' || sel.owner.kind === 'component') {
    S().toast(t('Layout elements can only move inside their own layout.'), 'error');
    return;
  }
  S().addOp({ kind: 'move', sel: sel.sel, to: target.sel, pos, label: t('Move') + ' ' + sel.label });
};

export const moveSibling = (dir: -1 | 1): void => {
  const sel = S().selected;
  if (!sel) return;
  const sibling = dir < 0 ? sel.el.previousElementSibling : sel.el.nextElementSibling;
  if (!sibling || sibling.localName === 'template') return;
  moveSelected(sibling, dir < 0 ? 'before' : 'after');
};

export const wrapSelected = (wrapper = '<div class="sve-box">'): void => {
  const sel = S().selected;
  if (!sel) return;
  if (sel.owner.kind !== 'theme' && sel.sid) {
    editOwner(sel.owner, sel.sid, 'wrap', { wrapper });
    return;
  }
  S().toast(t('Wrapping works on builder elements. On theme markup, insert a container and move elements into it.'), 'info');
};

export const changeTag = (tag: string): void => {
  const sel = S().selected;
  if (!sel) return;
  if (sel.owner.kind !== 'theme' && sel.sid) {
    const source = sourceOf(sel.owner);
    const outer = source ? outerOf(source, sel.sid) : null;
    if (!outer) return;
    const m = /^<([a-zA-Z][\w-]*)/.exec(outer);
    if (!m) return;
    const next = outer.replace(/^<[a-zA-Z][\w-]*/, '<' + tag).replace(new RegExp(`</${m[1]}>$`), `</${tag}>`);
    editOwner(sel.owner, sel.sid, 'replace', { html: next });
    return;
  }
  S().addOp({ kind: 'tag', sel: sel.sel, tag, label: `<${tag}> ${sel.label}` });
};

/** Markup of the source that owns an element. */
export const sourceOf = (owner: Owner): string | null => {
  const s = S();
  if (owner.kind === 'post') return s.sources[owner.id]?.source ?? null;
  if (owner.kind === 'component') return s.doc.components.find((c) => c.id === owner.id)?.html ?? null;
  if (owner.kind === 'op') return s.doc.ops.find((o) => o.id === owner.id)?.html ?? null;
  return null;
};

/** Where a new element goes: next to / inside the selection, or into the page's main area. */
export const insertHtml = (html: string, pos: 'before' | 'after' | 'append' | 'prepend' = 'after', label = t('Element')): void => {
  const s = S();
  const marked = ensureIds(html);
  const sel = s.selected;
  const postId = s.ctx?.builder.post?.id ?? null;

  // Builder element selected: insert into its layout.
  if (sel && sel.sid && sel.owner.kind !== 'theme') {
    const action: SourceAction = pos === 'before' ? 'insert_before' : pos === 'append' ? 'append' : pos === 'prepend' ? 'prepend' : 'insert_after';
    editOwner(sel.owner, sel.sid, action, { html: marked });
    return;
  }
  // Builder page without a selection: append to the end of its layout.
  if (!sel && postId && s.sources[postId]) {
    s.setSourceText(postId, s.sources[postId].source + '\n' + marked);
    void refreshAfterSource(/data-sid="([^"]+)"/.exec(marked)?.[1]);
    return;
  }
  // Theme markup: a structural op.
  const doc = frameDoc();
  const anchor = sel?.sel ?? (doc?.querySelector('main') ? 'main' : 'body');
  const op: Omit<Op, 'id' | 'scope' | 'page' | 'tpl'> = { kind: 'insert', sel: anchor, pos: sel ? pos : 'append', html: marked, label };
  const id = s.addOp(op);
  const first = /data-sid="([^"]+)"/.exec(marked)?.[1];
  window.setTimeout(() => {
    const el = first ? findAgain(`[data-sid="${first}"]`) : findAgain(`[data-ve-id="${id}"]`);
    if (el) select(el);
  }, 80);
};

/** Inserts a component (reference: later changes of the component apply everywhere). */
export const insertComponent = (id: string, props: Record<string, unknown> = {}): void => {
  const s = S();
  const c = s.doc.components.find((x) => x.id === id);
  if (!c) return;
  const sel = s.selected;
  const postId = s.ctx?.builder.post?.id ?? null;
  const propsAttr = Object.keys(props).length ? ` props='${JSON.stringify(props).replace(/'/g, '&#39;')}'` : '';
  const tag = c.kind === 'react' ? `<sve-island ref="${c.name}"${propsAttr}></sve-island>` : `<sve-component ref="${c.name}"${propsAttr}></sve-component>`;
  if ((sel && sel.sid && sel.owner.kind === 'post') || (!sel && postId && s.sources[postId])) {
    insertHtml(tag, 'after', c.name);
    return;
  }
  const anchor = sel?.sel ?? 'main';
  s.addOp({ kind: 'insert', sel: anchor, pos: sel ? 'after' : 'append', html: '', comp: c.id, props, label: c.name });
};

/** Inline text editing in the preview (double click). */
export const startInlineEdit = (el: HTMLElement): void => {
  const s = S();
  const sel = selectionFor(el);
  let mode: 'plain' | 'rich' = el.children.length === 0 ? 'plain' : 'rich';

  if (sel.owner.kind !== 'theme' && sel.sid) {
    const source = sourceOf(sel.owner);
    const inner = source ? innerOf(source, sel.sid) : null;
    if (inner === null) return;
    if (/\{\{|<sve-/.test(inner)) {
      s.toast(t('This text comes from data or builder tags — edit it in the Content tab.'), 'info');
      useStore.setState({ right: 'content' });
      return;
    }
    mode = isPlainText(inner) ? 'plain' : 'rich';
  }
  if (['img', 'input', 'video', 'iframe', 'svg', 'select', 'textarea', 'body', 'html'].includes(el.localName)) return;

  const before = mode === 'plain' ? el.textContent ?? '' : el.innerHTML;
  el.setAttribute('contenteditable', mode === 'plain' ? 'plaintext-only' : 'true');
  if (el.getAttribute('contenteditable') !== 'plaintext-only' && mode === 'plain') el.setAttribute('contenteditable', 'true');
  el.setAttribute('data-sve-editing', '');
  el.focus();
  const range = el.ownerDocument.createRange();
  range.selectNodeContents(el);
  const selection = el.ownerDocument.getSelection();
  selection?.removeAllRanges();
  selection?.addRange(range);

  const finish = (commit: boolean) => {
    el.removeEventListener('blur', onBlur);
    el.removeEventListener('keydown', onKey);
    el.removeAttribute('contenteditable');
    el.removeAttribute('data-sve-editing');
    const after = mode === 'plain' ? el.textContent ?? '' : el.innerHTML;
    if (!commit) {
      if (mode === 'plain') el.textContent = before;
      else el.innerHTML = before;
      return;
    }
    if (after === before) return;
    if (sel.owner.kind !== 'theme' && sel.sid) {
      if (mode === 'plain') editOwnerQuiet(sel.owner, sel.sid, 'set_text', { text: after });
      else editOwnerQuiet(sel.owner, sel.sid, 'set_inner', { html: after.replace(/\sdata-sve-editing=""/g, '') });
      return;
    }
    s.updateEdit(sel.sel, (e) => {
      if (mode === 'plain') {
        e.text = after;
        delete e.html;
      } else {
        e.html = after;
        delete e.text;
      }
    }, undefined, sel.label);
  };
  const onBlur = () => finish(true);
  const onKey = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      finish(false);
    } else if (e.key === 'Enter' && mode === 'plain' && !e.shiftKey) {
      e.preventDefault();
      el.blur();
    }
  };
  el.addEventListener('blur', onBlur);
  el.addEventListener('keydown', onKey);
};

/** Source edit whose result is already visible in the preview (no reload). */
const editOwnerQuiet = (owner: Owner, sid: string, action: SourceAction, args: SourceArgs) => {
  const s = S();
  if (owner.kind === 'post') {
    s.editSource(owner.id, action, sid, args);
    return;
  }
  editOwner(owner, sid, action, args);
};

/** Attribute change of an element (href, src, alt, class…). */
export const setAttribute = (name: string, value: string | null): void => {
  const sel = S().selected;
  if (!sel) return;
  if (value === null) sel.el.removeAttribute(name);
  else sel.el.setAttribute(name, value);
  if (sel.owner.kind !== 'theme' && sel.sid) {
    editOwnerQuiet(sel.owner, sel.sid, 'set_attrs', { attrs: { [name]: value } });
    return;
  }
  S().updateEdit(sel.sel, (e) => {
    e.attrs = { ...(e.attrs ?? {}), [name]: value };
  }, undefined, sel.label);
};

/** Replaces the inner markup / text of the selected element. */
export const setContent = (kind: 'text' | 'html', value: string): void => {
  const sel = S().selected;
  if (!sel) return;
  if (sel.owner.kind !== 'theme' && sel.sid) {
    if (kind === 'text') {
      sel.el.textContent = value;
      editOwnerQuiet(sel.owner, sel.sid, 'set_text', { text: value });
    } else {
      editOwner(sel.owner, sel.sid, 'set_inner', { html: value });
    }
    return;
  }
  S().updateEdit(sel.sel, (e) => {
    if (kind === 'text') {
      e.text = value;
      delete e.html;
    } else {
      e.html = value;
      delete e.text;
    }
  }, undefined, sel.label);
};

/** Turns the selection into a reusable component and replaces it with a reference. */
export const saveSelectionAsComponent = (name: string): void => {
  const s = S();
  const sel = s.selected;
  if (!sel) return;
  const source = sel.owner.kind !== 'theme' && sel.sid ? sourceOf(sel.owner) : null;
  const markup = (source && sel.sid ? outerOf(source, sel.sid) : null) ?? sel.el.outerHTML.replace(/\sdata-ve-(id|ops)="[^"]*"/g, '');
  const id = Math.random().toString(36).slice(2, 12);
  s.setDoc((d) => {
    d.components.push({ id, name, kind: 'html', category: '', html: ensureIds(stripIds(markup)), css: '', props: [] });
  });
  if (sel.owner.kind === 'post' && sel.sid) {
    editOwner(sel.owner, sel.sid, 'replace', { html: `<sve-component ref="${name}"></sve-component>` });
  }
  s.toast(t('Component “{name}” saved.', { name }), 'success');
};

export const selectParent = (): void => {
  const sel = S().selected;
  const parent = sel?.el.parentElement;
  if (parent && parent.localName !== 'html') select(parent);
};

export const ensureSidOrToast = ensureSid;
