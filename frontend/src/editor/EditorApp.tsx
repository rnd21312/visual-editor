import { useCallback, useEffect, useMemo, useReducer, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';
import { createApi } from '@/admin/lib/api';
import {
  buildCss,
  editsForPage,
  hasContent,
  pageKey,
  resolveOps,
  serializeDoc,
  type ComponentKind,
  type Device,
  type Edit,
  type Op,
  type Pos,
  type Scope,
  type State,
  type VisualDoc,
} from '@/lib/visualCss';
import { ApiModal } from './ApiModal';
import { Canvas, type CanvasHandle, type InlineChange } from './Canvas';
import { ContextMenu, type MenuItem } from './ContextMenu';
import { EditorContext, type Actions, type EditorContextValue } from './context';
import { armDrag, DragLayer, type DragPayload } from './DragLayer';
import { PanelFrame, Splitter } from './Dock';
import { PagePicker } from './PagePicker';
import { columnWidth, useLayout } from './layout';
import {
  anchorFor,
  canContain,
  cleanHtml,
  makeInsertOp,
  makeMoveOp,
  makeRemoveOp,
  makeTagOp,
  withOp,
  type Drop,
} from './ops';
import { AddPanel } from './panels/AddPanel';
import { AllPanel } from './panels/AllPanel';
import { ChangesPanel } from './panels/ChangesPanel';
import { ClassesPanel } from './panels/ClassesPanel';
import { ContentPanel } from './panels/ContentPanel';
import { CssPanel } from './panels/CssPanel';
import { GlobalPanel } from './panels/GlobalPanel';
import { RuleBar } from './panels/RuleBar';
import { SeoPanel } from './panels/SeoPanel';
import { StylePanel } from './panels/StylePanel';
import { Tree } from './panels/Tree';
import { classSelector, describe, isPlaceholder, pathSelector, safeMatches } from './selector';
import { SettingsPopover } from './SettingsPopover';
import { blankEdit, initHistory, newId, reducer, setStyleProp } from './state';
import type { EditorConfig, Frame, Mode, Target, TemplateInfo } from './types';

type SaveState = { status: 'idle' | 'saving' | 'saved' | 'error'; message?: string };
type LeftTab = 'elements' | 'add' | 'classes' | 'changes' | 'site';
type RightTab = 'style' | 'all' | 'content' | 'css' | 'seo';
type Step = { op: Op; rebase?: { from: string; to: string } };
type Clipboard = { html: string; label: string };
type StyleClipboard = Pick<Edit, 'styles' | 'states' | 'css'>;

const DEVICE_LABELS: { id: Device; label: string; title: string }[] = [
  { id: 'base', label: 'Desktop', title: 'Desktop — edits apply to every width' },
  { id: 'tablet', label: 'Tablet', title: 'Tablet — edits apply at 1023px and below' },
  { id: 'mobile', label: 'Mobile', title: 'Mobile — edits apply at 767px and below' },
];

const LEFT_TABS: { id: LeftTab; label: string; title: string }[] = [
  { id: 'elements', label: 'Elements', title: 'Every tag of the page' },
  { id: 'add', label: 'Add', title: 'Insert tags, embed code, JSON and components' },
  { id: 'classes', label: 'Classes', title: 'Edit a CSS class for all elements that use it' },
  { id: 'changes', label: 'Changes', title: 'Everything you changed on this page' },
  { id: 'site', label: 'Site', title: 'Theme colours and CSS for the whole site' },
];

const RIGHT_TABS: { id: RightTab; label: string }[] = [
  { id: 'style', label: 'Style' },
  { id: 'all', label: 'All' },
  { id: 'content', label: 'Content' },
  { id: 'css', label: 'CSS' },
  { id: 'seo', label: 'SEO' },
];

const NO_TEMPLATE: TemplateInfo = { key: '', label: '', templated: false, count: 0 };

/**
 * Where an element's edits apply by default: header, footer and menus show on every page (whole site); on
 * templated pages (a product, a blog post…) everything else belongs to the template, not to one URL.
 */
const defaultScope = (el: Element, template: TemplateInfo): Scope =>
  el.closest('header, footer, nav') ? 'site' : template.templated ? 'template' : 'page';

const makeTarget = (el: HTMLElement, page: string, template: TemplateInfo): Target => ({
  sel: pathSelector(el),
  label: describe(el),
  scope: defaultScope(el, template),
  page,
  tpl: template.key,
});

const isRoot = (el: Element): boolean => el.localName === 'html' || el.localName === 'body' || el.localName === 'head';

export const EditorApp = ({ config }: { config: EditorConfig }) => {
  const api = useMemo(() => createApi(config), [config]);
  const canvas = useRef<CanvasHandle>(null);
  const { layout, setState: setPanel, setWidth, reset: resetLayout } = useLayout();

  const [hist, dispatch] = useReducer(reducer, config.doc, initHistory);
  const doc = hist.present;
  const dirty = doc !== hist.saved;

  const [frame, setFrame] = useState<Frame | null>(null);
  const [url, setUrl] = useState(config.startUrl);
  const [device, setDevice] = useState<Device>('base');
  const [mode, setMode] = useState<Mode>('inspect');
  const [state, setState] = useState<State | 'normal'>('normal');
  const [selected, setSelected] = useState<HTMLElement | null>(null);
  const [multi, setMulti] = useState<HTMLElement[]>([]);
  const [hovered, setHovered] = useState<HTMLElement | null>(null);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [version, bump] = useReducer((n: number) => n + 1, 0);
  const [leftTab, setLeftTab] = useState<LeftTab>('elements');
  const [rightTab, setRightTab] = useState<RightTab>('style');
  const [save, setSave] = useState<SaveState>({ status: 'idle' });
  const [draftScope, setDraftScope] = useState<Scope | null>(null);
  const [draftSel, setDraftSel] = useState<string | null>(null);
  const [insertAt, setInsertAt] = useState<Pos>('after');
  const [drag, setDrag] = useState<DragPayload | null>(null);
  const [clipboard, setClipboard] = useState<Clipboard | null>(null);
  const [styleClip, setStyleClip] = useState<StyleClipboard | null>(null);
  const [menu, setMenu] = useState<{ x: number; y: number; doc: Document } | null>(null);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [apiOpen, setApiOpen] = useState(false);
  const [draftInfo, setDraftInfo] = useState(config.draft);
  const [draftLoaded, setDraftLoaded] = useState(false);
  /** Selector of an element to select once the preview has applied the op that creates it. */
  const pendingSelect = useRef<string | null>(null);

  const page = useMemo(() => pageKey(new URL(url).pathname, config.homePath), [url, config.homePath]);
  const template = frame?.template ?? NO_TEMPLATE;
  const visible = useMemo(() => editsForPage(doc, page, template.key), [doc, page, template.key]);
  const scopeOf = (el: Element): Scope => defaultScope(el, template);

  /* ---- preview ---- */

  // Paint the current edits into the preview.
  useEffect(() => {
    if (!frame) return;
    frame.styleEl.textContent = buildCss(doc, page, true, template.key);
    const result = frame.applier.set(
      visible.filter(hasContent).map(({ sel, text, html, attrs }) => ({ sel, text, html, attrs })),
      resolveOps(doc, page, template.key),
    );

    // Undo / redo of a structural change cannot be unpicked in place: rebuild the page from scratch.
    if (result === 'reload') {
      canvas.current?.reload();
      return;
    }

    const pending = pendingSelect.current;
    if (pending) {
      let found: HTMLElement | null = null;
      try {
        found = frame.doc.querySelector<HTMLElement>(pending);
      } catch {
        found = null;
      }
      if (found) {
        pendingSelect.current = null;
        select(found);
        canvas.current?.scrollTo(found);
      }
    }
    bump();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [frame, doc, page, visible, template.key]);

  // Follow the page live: transitions, scrolling, hover effects and React updates all change what the
  // inspector shows, so re-read the computed styles a few times a second while something is selected.
  useEffect(() => {
    if (!selected) return;
    const timer = window.setInterval(() => {
      if (document.hidden) return;
      const typing = document.activeElement;
      if (typing && ['INPUT', 'TEXTAREA', 'SELECT'].includes(typing.tagName) && typing.closest('.ve-panel--right')) return;
      bump();
    }, 350);
    return () => window.clearInterval(timer);
  }, [selected]);

  // Re-evaluate what is displayed when the preview width changes.
  useEffect(() => {
    const timer = window.setTimeout(bump, 400);
    return () => window.clearTimeout(timer);
  }, [device]);

  // Show :hover / :focus / :active styles without holding the pointer there.
  useEffect(() => {
    if (!selected || state === 'normal') return;
    selected.setAttribute('data-ve-force', state);
    return () => selected.removeAttribute('data-ve-force');
  }, [selected, state]);

  // The selected element can disappear when React re-renders.
  useEffect(() => {
    if (selected && !selected.isConnected) setSelected(null);
    setMulti((current) => (current.some((el) => !el.isConnected) ? current.filter((el) => el.isConnected) : current));
  }, [selected, version]);

  const handleFrame = useCallback((next: Frame | null) => {
    setFrame(next);
    setSelected(null);
    setMulti([]);
    setHovered(null);
    setActiveId(null);
  }, []);

  const handleNavigate = useCallback((next: string) => {
    setUrl(next);
    try {
      window.history.replaceState(null, '', `?sve_editor=1&sve_url=${encodeURIComponent(next)}`);
    } catch {
      // The address bar is cosmetic.
    }
  }, []);

  /* ---- selection + the rule being edited ---- */

  const select = useCallback((el: HTMLElement | null) => {
    setSelected(el);
    setMulti([]);
    setActiveId(null);
    setState('normal');
    setDraftScope(null);
    setDraftSel(null);
  }, []);

  const toggleMulti = (el: HTMLElement) => {
    if (!selected) return select(el);
    if (el === selected) return;
    setMulti((current) => (current.includes(el) ? current.filter((other) => other !== el) : [...current, el]));
  };

  /** The selection plus Ctrl-clicked elements, in page order. */
  const targets = (): HTMLElement[] =>
    (selected ? [selected, ...multi] : []).sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1));

  const target = useMemo(() => {
    if (!selected) return null;
    const base = makeTarget(selected, page, template);
    const named = draftSel ? { ...base, sel: draftSel, label: draftSel } : base;
    return draftScope ? { ...named, scope: draftScope } : named;
  }, [selected, page, draftScope, draftSel, template]);
  const matching = useMemo(
    () => (selected ? visible.filter((edit) => safeMatches(selected, edit.sel)) : []),
    [selected, visible],
  );
  const activeEdit = useMemo<Edit | null>(() => {
    if (!selected || !target) return null;
    return (
      visible.find((edit) => edit.id === activeId) ??
      matching.find((edit) => edit.sel === target.sel) ??
      (draftSel ? null : matching[0]) ??
      null
    );
  }, [selected, target, visible, matching, activeId, draftSel]);

  const patch = useCallback(
    (fn: (edit: Edit) => Edit, key: string) => {
      if (!target) return;
      const fallback = blankEdit(target);
      const id = activeEdit?.id ?? fallback.id;
      dispatch({ type: 'edit', id, fallback, patch: fn, key, at: Date.now() });
      setActiveId(id);
    },
    [target, activeEdit],
  );

  const patchDoc = useCallback((fn: (current: VisualDoc) => VisualDoc, key: string) => {
    dispatch({ type: 'doc', fn, key, at: Date.now() });
  }, []);

  const removeEdit = useCallback((id: string) => {
    dispatch({ type: 'remove', id, at: Date.now() });
    setActiveId(null);
  }, []);

  /** Changes the element's own edit (the one keyed by its path), whatever rule is open in the inspector. */
  const patchOwn = (el: HTMLElement, fn: (edit: Edit) => Edit) => {
    const own = makeTarget(el, page, template);
    const mine = visible.filter((edit) => safeMatches(el, edit.sel));
    const existing = mine.find((edit) => edit.sel === own.sel);
    const fallback = blankEdit(own);
    dispatch({ type: 'edit', id: existing?.id ?? fallback.id, fallback, key: '', at: Date.now(), patch: fn });
  };

  /** Text typed straight onto the page (double-click). */
  const handleInline = (el: HTMLElement, change: InlineChange) =>
    patchOwn(el, (edit) => {
      const copy = { ...edit };
      delete copy.text;
      delete copy.html;
      return { ...copy, ...change };
    });

  /** "This page" / "Whole site": changes the active edit, or what the next edit will be created with. */
  const setScope = useCallback(
    (scope: Scope) => {
      if (activeEdit) patch((edit) => ({ ...edit, scope }), '');
      else setDraftScope(scope);
    },
    [activeEdit, patch],
  );

  /* ---- structure: insert, move, remove, re-tag, group ---- */

  const applySteps = (steps: Step[], selectAfter?: string) => {
    pendingSelect.current = selectAfter ?? null;
    dispatch({
      type: 'doc',
      fn: (current) => steps.reduce((acc, step) => withOp(acc, { ...step.op, tpl: template.key }, step.rebase), current),
      key: '',
      at: Date.now(),
    });
  };

  const contentRoot = (): HTMLElement | null =>
    frame?.doc.querySelector<HTMLElement>('main, #main, #content, [role="main"]') ?? frame?.doc.body ?? null;

  const insertHtml = (html: string, label: string, options: { pos?: Pos; comp?: string; exec?: boolean } = {}) => {
    if (!frame) return;
    let anchor: HTMLElement | null = selected;
    let pos: Pos = options.pos ?? insertAt;
    if (!anchor || isRoot(anchor)) {
      anchor = contentRoot();
      pos = 'append';
    } else if ((pos === 'append' || pos === 'prepend') && !canContain(anchor)) {
      pos = 'after';
    }
    if (!anchor) return;
    const op = makeInsertOp(pathSelector(anchor), pos, html, label, scopeOf(anchor), page, options.comp, options.exec);
    applySteps([{ op }], anchorFor(op));
  };

  /** The id an element ends up with after an op: the one it already has, or the op's own. */
  const idOf = (el: Element, op: Op): string => el.getAttribute('data-ve-id') ?? op.id;

  const moveStep = (el: HTMLElement, anchor: HTMLElement, pos: Pos): Step => {
    const from = pathSelector(el);
    const op = makeMoveOp(from, pathSelector(anchor), pos, describe(el), scopeOf(anchor), page);
    return { op, rebase: el.hasAttribute('data-ve-id') ? undefined : { from, to: `[data-ve-id="${idOf(el, op)}"]` } };
  };

  const relocate = (el: HTMLElement, anchor: HTMLElement, pos: Pos) => {
    if (el === anchor || el.contains(anchor) || isRoot(el)) return;
    const step = moveStep(el, anchor, pos);
    applySteps([step], `[data-ve-id="${idOf(el, step.op)}"]`);
  };

  const siblingOf = (el: HTMLElement, direction: -1 | 1): HTMLElement | null => {
    const step = (node: Element) => (direction < 0 ? node.previousElementSibling : node.nextElementSibling);
    let next = step(el);
    while (next && (isPlaceholder(next) || next.localName === 'script' || next.localName === 'style')) next = step(next);
    return next as HTMLElement | null;
  };

  const copy = (): Clipboard | null => {
    const list = targets().filter((el) => !isRoot(el));
    if (list.length === 0) return null;
    const html = list.map((el) => cleanHtml(el.outerHTML)).join('\n');
    const next = { html, label: list.length > 1 ? `${list.length} elements` : describe(list[0] as HTMLElement) };
    setClipboard(next);
    void navigator.clipboard?.writeText(html).catch(() => undefined);
    return next;
  };

  const removeSelection = () => {
    const list = targets().filter((el) => !isRoot(el));
    if (list.length === 0) return;
    applySteps(list.map((el) => ({ op: makeRemoveOp(pathSelector(el), describe(el), scopeOf(el), page) })));
    select(null);
  };

  const actions: Actions = {
    deselect: () => select(null),
    selectParent: () => {
      const parent = selected?.parentElement;
      if (parent && parent.localName !== 'html') select(parent);
    },
    duplicate: () => {
      const list = targets().filter((el) => !isRoot(el));
      if (list.length === 0) return;
      const steps = list.map((el) => ({
        op: makeInsertOp(pathSelector(el), 'after', el.outerHTML, `Copy of ${describe(el)}`, scopeOf(el), page),
      }));
      applySteps(steps, anchorFor((steps[steps.length - 1] as Step).op));
    },
    remove: removeSelection,
    moveBy: (direction) => {
      if (!selected) return;
      const sibling = siblingOf(selected, direction);
      if (sibling) relocate(selected, sibling, direction < 0 ? 'before' : 'after');
    },
    retag: (tag) => {
      if (!selected || selected.localName === tag) return;
      const from = pathSelector(selected);
      const op = makeTagOp(from, tag, `${describe(selected)} → ${tag}`, scopeOf(selected), page);
      const eid = idOf(selected, op);
      applySteps(
        [{ op, rebase: selected.hasAttribute('data-ve-id') ? undefined : { from, to: `[data-ve-id="${eid}"]` } }],
        `[data-ve-id="${eid}"]`,
      );
    },
    insert: (html, label, comp, exec) => insertHtml(html, label, { comp, exec }),
    insertCode: (kind: ComponentKind, code, label) => insertHtml(code, label, { exec: kind === 'embed' }),
    startDrag: (_event, payload) => setDrag(payload),
    insertAt,
    setInsertAt,

    copy: () => void copy(),
    cut: () => {
      if (copy()) removeSelection();
    },
    paste: (pos) => {
      const source = clipboard;
      if (source) insertHtml(source.html, source.label, { pos });
    },
    hasClipboard: clipboard !== null,
    copyStyle: () => {
      if (activeEdit) setStyleClip({ styles: activeEdit.styles, states: activeEdit.states, css: activeEdit.css });
    },
    pasteStyle: () => {
      if (styleClip) patch((edit) => ({ ...edit, styles: styleClip.styles, states: styleClip.states, css: styleClip.css }), '');
    },
    hasStyleClipboard: styleClip !== null,
    group: () => {
      const list = targets().filter((el) => !isRoot(el));
      const first = list[0];
      if (!first) return;
      const wrapper = makeInsertOp(pathSelector(first), 'before', '<div></div>', 'Group', scopeOf(first), page);
      const steps: Step[] = [{ op: wrapper }];
      const into = `[data-ve-id="${wrapper.id}"]`;
      for (const el of list) {
        const from = pathSelector(el);
        const op = makeMoveOp(from, into, 'append', describe(el), scopeOf(first), page);
        steps.push({ op, rebase: el.hasAttribute('data-ve-id') ? undefined : { from, to: `[data-ve-id="${idOf(el, op)}"]` } });
      }
      applySteps(steps, into);
    },
    ungroup: () => {
      if (!selected || isRoot(selected)) return;
      const kids = Array.from(selected.children).filter((child) => !isPlaceholder(child) && child.localName !== 'script') as HTMLElement[];
      if (kids.length === 0) return;
      const steps: Step[] = kids.map((child) => moveStep(child, selected, 'before'));
      steps.push({ op: makeRemoveOp(pathSelector(selected), describe(selected), scopeOf(selected), page) });
      applySteps(steps, steps[0] ? `[data-ve-id="${idOf(kids[0] as HTMLElement, (steps[0] as Step).op)}"]` : undefined);
    },
    editText: () => {
      if (selected) canvas.current?.inline(selected);
    },
    copySelector: () => {
      if (target) void navigator.clipboard?.writeText(target.sel).catch(() => undefined);
    },
    resetChanges: () => {
      if (!selected) return;
      const own = pathSelector(selected);
      patchDoc((current) => ({ ...current, edits: current.edits.filter((edit) => edit.sel !== own && edit.id !== activeEdit?.id) }), '');
    },
    saveAsComponent: (name) => {
      if (!selected) return;
      const component = { id: newId(), name: name || describe(selected), html: cleanHtml(selected.outerHTML), css: '', kind: 'html' as const };
      patchDoc((current) => ({ ...current, components: [...(current.components ?? []), component] }), '');
      setLeftTab('add');
      setPanel('left', 'docked');
    },

    editClass: (name) => {
      const holder = selected?.classList.contains(name) ? selected : frame?.doc.querySelector<HTMLElement>(classSelector(name));
      if (!holder) return;
      setSelected(holder);
      setMulti([]);
      setActiveId(null);
      setState('normal');
      setDraftSel(classSelector(name));
      setDraftScope('site');
      setRightTab('style');
    },
    addClass: (name) => {
      if (!selected) return;
      patchOwn(selected, (edit) => {
        const classes = new Set((edit.attrs?.class ?? selected.getAttribute('class') ?? '').split(/\s+/).filter(Boolean));
        classes.add(name);
        return { ...edit, attrs: { ...(edit.attrs ?? {}), class: Array.from(classes).join(' ') } };
      });
      setActiveId(null);
      setDraftSel(classSelector(name));
      setDraftScope('site');
      setRightTab('style');
    },
    removeClass: (name) => {
      if (!selected) return;
      patchOwn(selected, (edit) => {
        const classes = (edit.attrs?.class ?? selected.getAttribute('class') ?? '').split(/\s+/).filter((cls) => cls && cls !== name);
        return { ...edit, attrs: { ...(edit.attrs ?? {}), class: classes.join(' ') } };
      });
    },

    toggleMulti,
    openMenu: (el, x, y, menuDoc) => {
      if (el !== selected && !multi.includes(el)) select(el);
      setMenu({ x, y, doc: menuDoc });
    },
    targets,
  };

  const handleDrop = (drop: Drop, payload: DragPayload) => {
    setDrag(null);
    const { anchor, pos } = drop;
    if (anchor.localName === 'html') return;

    if (payload.kind === 'new') {
      const where = anchor.localName === 'body' ? contentRoot() : anchor;
      if (!where) return;
      const op = makeInsertOp(pathSelector(where), where === anchor ? pos : 'append', payload.html, payload.label, scopeOf(where), page, payload.comp);
      applySteps([{ op }], anchorFor(op));
    } else {
      relocate(payload.source, anchor, pos);
    }
  };

  /** Drag handle on the selected element: reorder by default, Alt = free positioning with translate. */
  const onHandleDown = (event: ReactPointerEvent) => {
    if (!selected) return;
    event.preventDefault();
    event.stopPropagation();
    const source = selected;

    if (!event.altKey) {
      armDrag(event, () => setDrag({ kind: 'move', source, label: describe(source) }));
      return;
    }

    const handle = event.currentTarget as HTMLElement;
    handle.setPointerCapture(event.pointerId);
    const startX = event.clientX;
    const startY = event.clientY;
    const current = (activeEdit?.styles[device]?.translate ?? '').split(' ').map((part) => Number.parseFloat(part));
    const baseX = current[0] || 0;
    const baseY = current[1] || 0;

    const move = (next: PointerEvent) => {
      const value = `${Math.round(baseX + next.clientX - startX)}px ${Math.round(baseY + next.clientY - startY)}px`;
      patch((edit) => setStyleProp(edit, device, 'normal', 'translate', value), `free:${target?.sel ?? ''}`);
    };
    const up = () => {
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', up);
    };
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', up);
  };

  /* ---- saving ---- */

  const persist = useCallback(async () => {
    setSave({ status: 'saving' });
    try {
      await api.put('admin/visual', serializeDoc(doc));
      dispatch({ type: 'saved', doc });
      setSave({ status: 'saved' });
      if (draftLoaded) {
        // The reviewed AI draft is now live; the proposal is no longer pending.
        await api.del('admin/draft').catch(() => undefined);
        setDraftLoaded(false);
        setDraftInfo(null);
      }
    } catch (error) {
      setSave({ status: 'error', message: error instanceof Error ? error.message : 'Could not save.' });
    }
  }, [api, doc, draftLoaded]);

  useEffect(() => {
    if (save.status !== 'saved') return;
    const timer = window.setTimeout(() => setSave({ status: 'idle' }), 2500);
    return () => window.clearTimeout(timer);
  }, [save]);

  useEffect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  /* ---- AI drafts ---- */

  const previewDraft = () =>
    api.get<VisualDoc | null>('admin/draft').then((proposed) => {
      if (!proposed) return setDraftInfo(null);
      dispatch({ type: 'doc', fn: () => proposed, key: '', at: Date.now() });
      setDraftLoaded(true);
      setApiOpen(false);
    });

  const discardDraft = () =>
    api.del('admin/draft').then(() => {
      setDraftInfo(null);
      setDraftLoaded(false);
    });

  // A draft may arrive while the editor is open: look again when the window gets focus.
  useEffect(() => {
    const check = () =>
      api
        .get<VisualDoc | null>('admin/draft')
        .then((proposed) => setDraftInfo((current) => (proposed ? { changes: current?.changes ?? 1 } : null)))
        .catch(() => undefined);
    window.addEventListener('focus', check);
    return () => window.removeEventListener('focus', check);
  }, [api]);

  const goTo = (target: string) => {
    setPickerOpen(false);
    canvas.current?.navigate(target);
  };

  /* ---- keyboard (also fed by the preview and detached panels) ---- */

  const live = useRef({ actions, selected, dirty, persist });
  live.current = { actions, selected, dirty, persist };

  const onKey = useCallback((event: KeyboardEvent) => {
    const { actions: act, selected: current, dirty: unsaved, persist: saveNow } = live.current;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      setPickerOpen(true);
      return;
    }
    const el = event.target as HTMLElement | null;
    const typing = Boolean(el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)));
    const mod = event.ctrlKey || event.metaKey;
    const key = event.key.toLowerCase();
    const run = (fn: () => void) => {
      event.preventDefault();
      fn();
    };

    if (mod && key === 's') run(() => unsaved && void saveNow());
    else if (typing) return;
    else if (mod && key === 'z') run(() => dispatch({ type: event.shiftKey ? 'redo' : 'undo' }));
    else if (mod && key === 'y') run(() => dispatch({ type: 'redo' }));
    else if (mod && key === 'd' && current) run(act.duplicate);
    else if (mod && key === 'c' && current) run(act.copy);
    else if (mod && key === 'x' && current) run(act.cut);
    else if (mod && key === 'v') run(() => act.paste('after'));
    else if (mod && key === 'g' && current) run(event.shiftKey ? act.ungroup : act.group);
    else if ((key === 'delete' || key === 'backspace') && current) run(act.remove);
    else if (key === 'escape') act.deselect();
  }, []);

  useEffect(() => {
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onKey]);

  /* ---- context menu ---- */

  const menuItems = (): MenuItem[] => {
    const root = !selected || isRoot(selected);
    const many = multi.length > 0;
    const hasKids = Boolean(selected && Array.from(selected.children).some((child) => !isPlaceholder(child)));
    return [
      { label: 'Edit text', hint: 'Double-click', disabled: root || many, onClick: actions.editText },
      { label: 'Select parent', disabled: !selected?.parentElement || selected.parentElement.localName === 'html', onClick: actions.selectParent },
      'separator',
      { label: 'Copy', hint: 'Ctrl+C', disabled: root, onClick: actions.copy },
      { label: 'Cut', hint: 'Ctrl+X', disabled: root, onClick: actions.cut },
      { label: 'Paste after', hint: 'Ctrl+V', disabled: !clipboard, onClick: () => actions.paste('after') },
      { label: 'Paste inside', disabled: !clipboard || (selected ? !canContain(selected) : false), onClick: () => actions.paste('append') },
      { label: 'Duplicate', hint: 'Ctrl+D', disabled: root, onClick: actions.duplicate },
      'separator',
      { label: many ? `Group ${multi.length + 1} elements` : 'Group in a container', hint: 'Ctrl+G', disabled: root, onClick: actions.group },
      { label: 'Ungroup (unwrap)', hint: 'Ctrl+Shift+G', disabled: root || many || !hasKids, onClick: actions.ungroup },
      { label: 'Move up', disabled: root, onClick: () => actions.moveBy(-1) },
      { label: 'Move down', disabled: root, onClick: () => actions.moveBy(1) },
      'separator',
      { label: 'Copy style', disabled: !activeEdit, onClick: actions.copyStyle },
      { label: 'Paste style', disabled: !styleClip, onClick: actions.pasteStyle },
      { label: 'Save as component', disabled: root || many, onClick: () => actions.saveAsComponent() },
      { label: 'Copy selector', disabled: !selected, onClick: actions.copySelector },
      { label: 'Reset my changes', disabled: !matching.length, onClick: actions.resetChanges },
      'separator',
      { label: 'Delete', hint: 'Del', danger: true, disabled: root, onClick: actions.remove },
    ];
  };

  /* ---- render ---- */

  const context: EditorContextValue = {
    config,
    doc,
    page,
    device,
    state,
    setState,
    frame,
    selected,
    multi,
    template,
    hovered,
    version,
    target,
    matching,
    activeEdit,
    select,
    hover: setHovered,
    setActiveId,
    patch,
    patchDoc,
    removeEdit,
    setScope,
    actions,
  };

  const pageLabel = (frame?.doc.title.split(/\s[–—|-]\s/)[0] ?? '').trim() || new URL(url).pathname;
  const ancestors: HTMLElement[] = [];
  for (let el = selected; el && el.localName !== 'html'; el = el.parentElement) ancestors.unshift(el);

  const leftDocked = layout.left === 'docked';
  const rightDocked = layout.right === 'docked';
  const focusMode = !leftDocked && !rightDocked;

  return (
    <EditorContext.Provider value={context}>
      <div
        className="ve-app"
        style={{
          gridTemplateColumns: `${columnWidth(layout, 'left')} ${leftDocked ? '5px' : '0px'} minmax(0, 1fr) ${rightDocked ? '5px' : '0px'} ${columnWidth(layout, 'right')}`,
        }}
      >
        <header className="ve-top">
          <button
            type="button"
            className={`ve-icon-btn ${leftDocked ? 'is-on' : ''}`}
            title={leftDocked ? 'Hide the left panel' : 'Show the left panel'}
            aria-pressed={leftDocked}
            onClick={() => setPanel('left', leftDocked ? 'collapsed' : 'docked')}
          >
            ☰
          </button>
          <a className="ve-top__back" href={config.adminUrl} title="Back to the dashboard">
            ← Dashboard
          </a>
          <strong className="ve-top__title">Visual editor</strong>

          <button type="button" className="ve-pagebtn" onClick={() => setPickerOpen(true)} title="Choose another page (Ctrl+K)">
            <span aria-hidden>▤</span>
            <span className="ve-pagebtn__text">{pageLabel}</span>
            <span aria-hidden>▾</span>
          </button>
          {template.templated && (
            <span className="ve-tplchip" title={`Edits you make here apply to every item that uses this template${template.count ? ` (${template.count})` : ''}`}>
              {template.label}
              {template.count > 0 && ` · ${template.count}`}
            </span>
          )}
          <button type="button" className="ve-icon-btn" title="Reload the page" onClick={() => canvas.current?.reload()}>
            ↻
          </button>

          <div className="ve-seg2" role="group" aria-label="Mode">
            <button type="button" className={mode === 'inspect' ? 'is-on' : ''} onClick={() => setMode('inspect')} title="Click elements to select and edit them">
              Select
            </button>
            <button
              type="button"
              className={mode === 'browse' ? 'is-on' : ''}
              onClick={() => {
                setMode('browse');
                setHovered(null);
              }}
              title="Use the page normally: follow links, open menus"
            >
              Browse
            </button>
          </div>

          <div className="ve-seg2" role="group" aria-label="Screen size">
            {DEVICE_LABELS.map((entry) => (
              <button key={entry.id} type="button" className={device === entry.id ? 'is-on' : ''} title={entry.title} onClick={() => setDevice(entry.id)}>
                {entry.label}
              </button>
            ))}
          </div>

          <span className="ve-spacer" />

          <button type="button" className="ve-icon-btn" title="Undo (Ctrl+Z)" disabled={hist.past.length === 0} onClick={() => dispatch({ type: 'undo' })}>
            ↶
          </button>
          <button type="button" className="ve-icon-btn" title="Redo (Ctrl+Shift+Z)" disabled={hist.future.length === 0} onClick={() => dispatch({ type: 'redo' })}>
            ↷
          </button>
          <a className="ve-top__link" href={url} target="_blank" rel="noreferrer">
            View live ↗
          </a>
          <span className={`ve-status ve-status--${save.status}`} role="status">
            {save.status === 'saving' && 'Saving…'}
            {save.status === 'saved' && 'Saved ✓'}
            {save.status === 'error' && (save.message ?? 'Could not save')}
            {save.status === 'idle' && dirty && 'Unsaved changes'}
          </span>
          <button type="button" className="ve-btn ve-btn--primary" disabled={!dirty || save.status === 'saving'} onClick={() => void persist()} title="Save (Ctrl+S)">
            Save
          </button>

          <button
            type="button"
            className={`ve-icon-btn ${focusMode ? 'is-on' : ''}`}
            title={focusMode ? 'Show the panels' : 'Focus on the page (hide both panels)'}
            aria-pressed={focusMode}
            onClick={() => {
              setPanel('left', focusMode ? 'docked' : 'collapsed');
              setPanel('right', focusMode ? 'docked' : 'collapsed');
            }}
          >
            ⛶
          </button>
          {config.canAdmin && (
            <button type="button" className={`ve-icon-btn ${draftInfo ? 'has-badge' : ''}`} title="AI access: API key and endpoint" onClick={() => setApiOpen(true)}>
              ✦
            </button>
          )}
          <SettingsPopover
            canAdmin={config.canAdmin}
            access={doc.access}
            onAccess={(access) => patchDoc((current) => ({ ...current, access }), 'access')}
            onResetLayout={resetLayout}
          />
          <button
            type="button"
            className={`ve-icon-btn ${rightDocked ? 'is-on' : ''}`}
            title={rightDocked ? 'Hide the right panel' : 'Show the right panel'}
            aria-pressed={rightDocked}
            onClick={() => setPanel('right', rightDocked ? 'collapsed' : 'docked')}
          >
            ▤
          </button>
        </header>

        <PanelFrame side="left" title="Navigator" state={layout.left} onState={(next) => setPanel('left', next)} onKey={onKey}>
          <div className="ve-tabs" role="tablist">
            {LEFT_TABS.map((tab) => (
              <button
                key={tab.id}
                type="button"
                role="tab"
                title={tab.title}
                aria-selected={leftTab === tab.id}
                className={leftTab === tab.id ? 'is-on' : ''}
                onClick={() => setLeftTab(tab.id)}
              >
                {tab.label}
              </button>
            ))}
          </div>
          <div className="ve-scroll">
            {leftTab === 'elements' && <Tree />}
            {leftTab === 'add' && <AddPanel />}
            {leftTab === 'classes' && <ClassesPanel />}
            {leftTab === 'changes' && <ChangesPanel />}
            {leftTab === 'site' && <GlobalPanel />}
          </div>
        </PanelFrame>
        <Splitter side="left" width={layout.leftW} onWidth={(width) => setWidth('left', width)} onReset={resetLayout} hidden={!leftDocked} />

        <main className="ve-center">
          {draftInfo && (
            <div className="ve-banner" role="status">
              <strong>✦ An AI proposed {draftInfo.changes > 0 ? draftInfo.changes : ''} change{draftInfo.changes === 1 ? '' : 's'}.</strong>
              {draftLoaded ? (
                <span>You are previewing them. Save to publish, or discard.</span>
              ) : (
                <span>Nothing is live until you approve.</span>
              )}
              {!draftLoaded && (
                <button type="button" className="ve-btn ve-btn--primary ve-mini" onClick={() => void previewDraft()}>
                  Review in the editor
                </button>
              )}
              <button type="button" className="ve-btn ve-mini" onClick={() => void discardDraft()}>
                Discard
              </button>
            </div>
          )}
          <Canvas
            ref={canvas}
            initialUrl={config.startUrl}
            previewArg={config.previewArg}
            device={device}
            mode={mode}
            selected={selected}
            hovered={hovered}
            multi={multi}
            onToggleMulti={toggleMulti}
            onContext={(el, x, y) => actions.openMenu(el, x, y, document)}
            onFrame={handleFrame}
            onNavigate={handleNavigate}
            onSelect={select}
            onHover={setHovered}
            onKey={onKey}
            onInline={handleInline}
            onHandleDown={onHandleDown}
          />
          <nav className="ve-crumbs" aria-label="Selected element path">
            {ancestors.length === 0 ? (
              <span className="ve-crumbs__hint">
                {mode === 'inspect'
                  ? 'Click anything to edit it · Double-click text to type · Right-click for more · Ctrl+click to select several'
                  : 'Browse mode: use the page normally.'}
              </span>
            ) : (
              ancestors.map((el) => (
                <button
                  key={pathSelector(el)}
                  type="button"
                  className={el === selected ? 'is-on' : ''}
                  onClick={() => select(el)}
                  onMouseEnter={() => setHovered(el)}
                  onMouseLeave={() => setHovered(null)}
                >
                  {describe(el, 1)}
                </button>
              ))
            )}
            {multi.length > 0 && <span className="ve-crumbs__multi">+{multi.length} selected</span>}
          </nav>
        </main>

        <Splitter side="right" width={layout.rightW} onWidth={(width) => setWidth('right', width)} onReset={resetLayout} hidden={!rightDocked} />
        <PanelFrame side="right" title="Inspector" state={layout.right} onState={(next) => setPanel('right', next)} onKey={onKey}>
          {selected ? (
            <>
              <RuleBar />
              <div className="ve-tabs" role="tablist">
                {RIGHT_TABS.map((tab) => (
                  <button
                    key={tab.id}
                    type="button"
                    role="tab"
                    aria-selected={rightTab === tab.id}
                    className={rightTab === tab.id ? 'is-on' : ''}
                    onClick={() => setRightTab(tab.id)}
                  >
                    {tab.label}
                  </button>
                ))}
              </div>
              <div className="ve-scroll">
                {rightTab === 'style' && <StylePanel />}
                {rightTab === 'all' && <AllPanel />}
                {rightTab === 'content' && <ContentPanel />}
                {rightTab === 'css' && <CssPanel />}
                {rightTab === 'seo' && <SeoPanel />}
              </div>
            </>
          ) : (
            <div className="ve-empty">
              <h2>Nothing selected</h2>
              <p>Click any element on the page, or pick one in the Elements list.</p>
              <ul>
                <li>Change text, links and images</li>
                <li>Change colours, fonts, spacing, borders…</li>
                <li>Edit a CSS class to restyle every element that uses it</li>
                <li>Right-click for copy, paste, group, delete…</li>
                <li>Add tags, embed code or JSON from the Add tab</li>
              </ul>
            </div>
          )}
        </PanelFrame>
      </div>

      {drag && frame && (
        <DragLayer
          payload={drag}
          frame={frame}
          iframe={() => canvas.current?.iframe() ?? null}
          onDrop={handleDrop}
          onCancel={() => setDrag(null)}
        />
      )}
      {pickerOpen && <PagePicker api={api} config={config} currentUrl={url} onPick={goTo} onClose={() => setPickerOpen(false)} />}
      {apiOpen && (
        <ApiModal
          api={api}
          initial={config.api}
          onClose={() => setApiOpen(false)}
          onRestore={(restored) => {
            dispatch({ type: 'doc', fn: () => restored, key: '', at: Date.now() });
            dispatch({ type: 'saved', doc: restored });
          }}
          onPreviewDraft={() => void previewDraft()}
          onDiscardDraft={() => void discardDraft()}
        />
      )}
      {menu && <ContextMenu x={menu.x} y={menu.y} doc={menu.doc} items={menuItems()} onClose={() => setMenu(null)} />}
    </EditorContext.Provider>
  );
};
