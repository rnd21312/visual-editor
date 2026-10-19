import { create } from 'zustand';
import type { Doc, Edit, Op, Scope, StateName, Device } from '../shared/types';
import { compileEdit } from '../shared/compile';
import { applySource, type SourceAction, type SourceArgs } from '../shared/source';
import type { Config } from './config';
import { createApi, ApiError, type Api } from './api';
import { t } from './i18n';

export type Ctx = {
  page: string;
  template: { key: string; label: string; templated: boolean; count: number };
  post: number;
  postType: string;
  title: string;
  builder: { post: { id: number; mode: string } | null; template: { id: number; target: string; mode: string; title: string } | null; header: number | null; footer: number | null };
  rev: number;
};

export type SourceInfo = { id: number; title: string; type: string; source: string; saved: string; mode: string; savedMode: string; trusted?: boolean };

export type Owner = { kind: 'post'; id: number } | { kind: 'component'; id: string } | { kind: 'op'; id: string } | { kind: 'theme' };

export type Selection = { el: Element; sel: string; sid: string | null; owner: Owner; label: string };

export type ViewDevice = 'desktop' | 'tablet' | 'mobile';
export type LeftTab = 'layers' | 'add' | 'pages' | 'components' | 'design' | 'seo' | 'ai';
export type RightTab = 'style' | 'content' | 'motion' | 'advanced';
export type Modal =
  | { type: 'settings' }
  | { type: 'revisions' }
  | { type: 'draft' }
  | { type: 'component'; id?: string; fromSelection?: boolean }
  | { type: 'new-content'; postType?: string }
  | { type: 'new-template'; target?: string }
  | { type: 'media'; onPick: (m: { id: number; url: string; alt: string }) => void }
  | { type: 'code'; title: string; value: string; lang: 'html' | 'css' | 'js' | 'jsx'; onSave: (v: string) => void }
  | { type: 'shortcuts' };

export type Toast = { id: number; text: string; kind: 'info' | 'success' | 'error' };
export type Activity = { id: string; time: number; actor: string; action: string; summary: string; rev?: number; post?: number };

type Snapshot = { doc: Doc; sources: Record<number, string> };

export const deviceOf = (d: ViewDevice): Device => (d === 'desktop' ? 'base' : d);

type State = {
  cfg: Config;
  api: Api;
  doc: Doc;
  base: Doc;
  sources: Record<number, SourceInfo>;
  url: string;
  ctx: Ctx | null;
  frameKey: number;
  loading: boolean;
  device: ViewDevice;
  scope: Scope;
  state: StateName;
  selected: Selection | null;
  hoverLabel: string;
  left: LeftTab;
  right: RightTab;
  modal: Modal | null;
  previewReady: boolean;
  saving: 'idle' | 'saving' | 'saved' | 'error';
  past: Snapshot[];
  future: Snapshot[];
  toasts: Toast[];
  activity: Activity[];
  draft: number;
  interact: boolean;
  liveNote: { selector: string; message: string } | null;
  remoteRev: number;
  /** Builder template shown on the current URL while it is edited (preview only). */
  tplPreview: number | null;
};

type Actions = {
  toast: (text: string, kind?: Toast['kind']) => void;
  setDoc: (fn: (d: Doc) => Doc | void, group?: string) => void;
  updateEdit: (sel: string, fn: (e: Edit) => void, target?: { scope: Scope; page: string; tpl: string }, label?: string) => void;
  removeEdit: (id: string) => void;
  addOp: (op: Omit<Op, 'id' | 'scope' | 'page' | 'tpl'> & Partial<Pick<Op, 'scope' | 'page' | 'tpl'>>) => string;
  loadSource: (id: number) => Promise<SourceInfo | null>;
  editSource: (id: number, action: SourceAction, sid: string, args?: SourceArgs) => string | undefined | false;
  setSourceText: (id: number, source: string, mode?: string) => void;
  select: (s: Selection | null) => void;
  navigate: (url: string) => void;
  reload: () => void;
  undo: () => void;
  redo: () => void;
  save: () => Promise<void>;
  discard: () => Promise<void>;
  target: () => { scope: Scope; page: string; tpl: string };
  dirty: () => boolean;
  refreshDoc: () => Promise<void>;
};

let toastId = 0;
let groupTimer = 0;
let lastGroup = '';
let workingTimer = 0;
const sourceTimers: Record<number, number> = {};

const clone = <T,>(v: T): T => (typeof structuredClone === 'function' ? structuredClone(v) : JSON.parse(JSON.stringify(v)));

export const newId = (): string => Math.random().toString(36).slice(2, 12);

export const useStore = create<State & Actions>()((set, get) => ({
  cfg: null as unknown as Config,
  api: null as unknown as Api,
  doc: null as unknown as Doc,
  base: null as unknown as Doc,
  sources: {},
  url: '',
  ctx: null,
  frameKey: 0,
  loading: true,
  device: 'desktop',
  scope: 'page',
  state: 'normal',
  selected: null,
  hoverLabel: '',
  left: 'layers',
  right: 'style',
  modal: null,
  previewReady: false,
  saving: 'idle',
  past: [],
  future: [],
  toasts: [],
  activity: [],
  draft: 0,
  interact: false,
  liveNote: null,
  remoteRev: 0,
  tplPreview: null,

  toast(text, kind = 'info') {
    const id = ++toastId;
    set((s) => ({ toasts: [...s.toasts, { id, text, kind }] }));
    window.setTimeout(() => set((s) => ({ toasts: s.toasts.filter((x) => x.id !== id) })), kind === 'error' ? 7000 : 3500);
  },

  dirty() {
    const s = get();
    return JSON.stringify(s.doc) !== JSON.stringify(s.base) || Object.values(s.sources).some((x) => x.source !== x.saved || x.mode !== x.savedMode);
  },

  target() {
    const { scope, ctx } = get();
    return { scope, page: ctx?.page ?? '/', tpl: ctx?.template.key ?? '' };
  },

  setDoc(fn, group) {
    const s = get();
    // Typing in one field is one undo step.
    const push = !group || group !== lastGroup;
    lastGroup = group ?? '';
    window.clearTimeout(groupTimer);
    groupTimer = window.setTimeout(() => (lastGroup = ''), 900);
    const next = clone(s.doc);
    const out = fn(next) ?? next;
    // Keep compiled CSS in sync for the preview.
    out.edits = out.edits.map((e) => ({ ...e, compiled: compileEdit(e, out.settings.breakpoints) }));
    set({
      doc: out,
      past: push ? [...s.past.slice(-80), snapshot(s)] : s.past,
      future: push ? [] : s.future,
      saving: 'idle',
    });
    scheduleWorking();
  },

  updateEdit(sel, fn, target, label) {
    const tg = target ?? get().target();
    get().setDoc((d) => {
      let edit = d.edits.find((e) => e.sel === sel && e.scope === tg.scope && (tg.scope !== 'page' || e.page === tg.page) && (tg.scope !== 'template' || e.tpl === tg.tpl));
      if (!edit) {
        edit = { id: newId(), scope: tg.scope, page: tg.page, tpl: tg.tpl, sel, label: label ?? sel, styles: {}, css: '' };
        d.edits.push(edit);
      }
      fn(edit);
    }, 'edit:' + sel + ':' + tg.scope);
  },

  removeEdit(id) {
    get().setDoc((d) => {
      d.edits = d.edits.filter((e) => e.id !== id);
      d.ops = d.ops.filter((o) => o.id !== id);
    });
  },

  addOp(op) {
    const id = newId();
    const tg = get().target();
    get().setDoc((d) => {
      d.ops.push({ ...tg, ...op, id } as Op);
    });
    return id;
  },

  async loadSource(id) {
    const existing = get().sources[id];
    if (existing) return existing;
    try {
      const post = await get().api.get<{ id: number; title: string; type: string; builder: { source: string; mode: string; working: { source: string; mode: string } | null } | null }>(`content/${id}`);
      if (!post.builder) return null;
      // A working copy left by an earlier session is dropped: the editor starts from what is saved.
      if (post.builder.working) await get().api.del(`content/${id}/working`).catch(() => undefined);
      const saved = post.builder.working ? (await get().api.get<{ builder: { source: string; mode: string } }>(`content/${id}`)).builder.source : post.builder.source;
      const info: SourceInfo = { id, title: post.title, type: post.type, source: saved, saved, mode: post.builder.mode, savedMode: post.builder.mode };
      set((s) => ({ sources: { ...s.sources, [id]: info } }));
      return info;
    } catch {
      return null;
    }
  },

  editSource(id, action, sid, args) {
    const info = get().sources[id];
    if (!info) return false;
    const result = applySource(info.source, sid, action, args);
    if (!result) {
      get().toast(t('That element was not found in the layout.'), 'error');
      return false;
    }
    get().setSourceText(id, result.source);
    return result.newSid;
  },

  setSourceText(id, source, mode) {
    const s = get();
    const info = s.sources[id];
    if (!info) return;
    set({
      sources: { ...s.sources, [id]: { ...info, source, mode: mode ?? info.mode } },
      past: [...s.past.slice(-80), snapshot(s)],
      future: [],
      saving: 'idle',
    });
    window.clearTimeout(sourceTimers[id]);
    sourceTimers[id] = window.setTimeout(() => {
      const cur = get().sources[id];
      if (cur) void get().api.put(`content/${id}/working`, { source: cur.source, mode: cur.mode });
    }, 400);
  },

  select(sel) {
    set({ selected: sel, liveNote: null });
  },

  navigate(url) {
    set({ url, selected: null, ctx: null, loading: true, frameKey: get().frameKey + 1 });
  },

  reload() {
    set({ frameKey: get().frameKey + 1, loading: true });
  },

  undo() {
    const s = get();
    const prev = s.past[s.past.length - 1];
    if (!prev) return;
    restore(prev, { past: s.past.slice(0, -1), future: [snapshot(s), ...s.future] });
  },

  redo() {
    const s = get();
    const next = s.future[0];
    if (!next) return;
    restore(next, { past: [...s.past, snapshot(s)], future: s.future.slice(1) });
  },

  async save() {
    const s = get();
    set({ saving: 'saving' });
    try {
      const warnings: string[] = [];
      if (JSON.stringify(s.doc) !== JSON.stringify(s.base)) {
        let res: { ok: boolean; doc: Doc; warnings: string[]; merged?: boolean };
        try {
          res = await s.api.put('doc', { doc: s.doc, rev: s.base.rev, summary: t('Saved in the editor') });
        } catch (e) {
          if (!(e instanceof ApiError) || e.status !== 409) throw e;
          // Someone (a person or an AI) saved meanwhile: keep both.
          res = await s.api.put('doc', { doc: s.doc, rev: s.base.rev, base: s.base });
          get().toast(t('The site changed while you were editing — both versions were merged.'), 'info');
        }
        warnings.push(...(res.warnings ?? []));
        set({ doc: res.doc, base: clone(res.doc) });
      }
      for (const info of Object.values(get().sources)) {
        if (info.source === info.saved && info.mode === info.savedMode) continue;
        await s.api.put(`content/${info.id}`, { builder: { source: info.source, mode: info.mode } });
        set((st) => ({ sources: { ...st.sources, [info.id]: { ...info, saved: info.source, savedMode: info.mode } } }));
      }
      await s.api.del('working').catch(() => undefined);
      set({ saving: 'saved' });
      warnings.forEach((w) => get().toast(w, 'info'));
      window.setTimeout(() => get().saving === 'saved' && set({ saving: 'idle' }), 2500);
    } catch (e) {
      set({ saving: 'error' });
      get().toast(e instanceof Error ? e.message : t('Could not save.'), 'error');
    }
  },

  async discard() {
    const s = get();
    await s.api.del('working').catch(() => undefined);
    for (const info of Object.values(s.sources)) {
      if (info.source !== info.saved || info.mode !== info.savedMode) await s.api.del(`content/${info.id}/working`).catch(() => undefined);
    }
    const sources: Record<number, SourceInfo> = {};
    for (const [id, info] of Object.entries(s.sources)) sources[Number(id)] = { ...info, source: info.saved, mode: info.savedMode };
    set({ doc: clone(s.base), sources, past: [], future: [] });
    get().reload();
  },

  async refreshDoc() {
    const doc = await get().api.get<Doc>('doc');
    const dirty = get().dirty();
    if (dirty) {
      // Keep local work; the next save merges.
      set({ remoteRev: doc.rev });
      return;
    }
    set({ doc, base: clone(doc), sources: {} });
    get().reload();
  },
}));

const snapshot = (s: State): Snapshot => ({
  doc: clone(s.doc),
  sources: Object.fromEntries(Object.values(s.sources).map((x) => [x.id, x.source])),
});

const restore = (snap: Snapshot, history: Pick<State, 'past' | 'future'>) => {
  const s = useStore.getState();
  const sources = { ...s.sources };
  let structural = false;
  for (const [id, src] of Object.entries(snap.sources)) {
    const info = sources[Number(id)];
    if (info && info.source !== src) {
      sources[Number(id)] = { ...info, source: src };
      structural = true;
      void s.api.put(`content/${id}/working`, { source: src, mode: info.mode });
    }
  }
  useStore.setState({ doc: snap.doc, sources, ...history });
  scheduleWorking();
  if (structural) window.setTimeout(() => useStore.getState().reload(), 450);
};

/** The preview renders components, tokens and libraries from the editor's unsaved document. */
const scheduleWorking = () => {
  window.clearTimeout(workingTimer);
  workingTimer = window.setTimeout(() => {
    const s = useStore.getState();
    if (JSON.stringify(s.doc) === JSON.stringify(s.base)) void s.api.del('working');
    else void s.api.put('working', { doc: s.doc });
  }, 700);
};

export const initStore = (cfg: Config) => {
  const doc = clone(cfg.doc);
  doc.edits = doc.edits.map((e) => ({ ...e, compiled: compileEdit(e, doc.settings.breakpoints) }));
  const api = createApi(cfg);
  useStore.setState({ cfg, api, doc, base: clone(doc), url: cfg.startUrl, draft: cfg.draft, remoteRev: doc.rev });
};

/** Pushes the working document now and resolves when the server has it (before reloading the preview). */
export const flushWorking = async (): Promise<void> => {
  window.clearTimeout(workingTimer);
  const s = useStore.getState();
  await s.api.put('working', { doc: s.doc }).catch(() => undefined);
};
