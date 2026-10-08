import { emptyDoc, isEmptyEdit, type Device, type Edit, type State, type VisualDoc } from '@/lib/visualCss';

/** The document plus undo/redo stacks. `saved` is the last version the server has. */
export type History = {
  past: VisualDoc[];
  present: VisualDoc;
  future: VisualDoc[];
  saved: VisualDoc;
  lastKey: string;
  lastAt: number;
};

export type Action =
  | { type: 'edit'; id: string; fallback: Edit; patch: (edit: Edit) => Edit; key: string; at: number }
  | { type: 'doc'; fn: (doc: VisualDoc) => VisualDoc; key: string; at: number }
  | { type: 'remove'; id: string; at: number }
  | { type: 'undo' }
  | { type: 'redo' }
  | { type: 'saved'; doc: VisualDoc };

const COALESCE_MS = 700;
const LIMIT = 100;

export const initHistory = (doc: VisualDoc | undefined): History => {
  const start = doc ?? emptyDoc();
  return { past: [], present: start, future: [], saved: start, lastKey: '', lastAt: 0 };
};

const prune = (doc: VisualDoc): VisualDoc => {
  const edits = doc.edits.filter((edit) => !isEmptyEdit(edit));
  return edits.length === doc.edits.length ? doc : { ...doc, edits };
};

/** Typing in one field produces one undo step instead of one per keystroke. */
const commit = (history: History, next: VisualDoc, key: string, at: number): History => {
  const doc = prune(next);
  if (doc === history.present) return history;
  const merge = key !== '' && key === history.lastKey && at - history.lastAt < COALESCE_MS;

  return {
    ...history,
    past: merge ? history.past : [...history.past.slice(-(LIMIT - 1)), history.present],
    present: doc,
    future: [],
    lastKey: key,
    lastAt: at,
  };
};

export const reducer = (history: History, action: Action): History => {
  switch (action.type) {
    case 'edit': {
      const { edits } = history.present;
      const index = edits.findIndex((edit) => edit.id === action.id);
      const next = action.patch(index >= 0 ? (edits[index] as Edit) : action.fallback);
      const list = index >= 0 ? edits.map((edit, i) => (i === index ? next : edit)) : [...edits, next];
      return commit(history, { ...history.present, edits: list }, action.key, action.at);
    }
    case 'doc':
      return commit(history, action.fn(history.present), action.key, action.at);
    case 'remove':
      return commit(
        history,
        { ...history.present, edits: history.present.edits.filter((edit) => edit.id !== action.id) },
        '',
        action.at,
      );
    case 'undo': {
      const previous = history.past[history.past.length - 1];
      if (!previous) return history;
      return {
        ...history,
        past: history.past.slice(0, -1),
        present: previous,
        future: [history.present, ...history.future],
        lastKey: '',
      };
    }
    case 'redo': {
      const [next, ...rest] = history.future;
      if (!next) return history;
      return { ...history, past: [...history.past, history.present], present: next, future: rest, lastKey: '' };
    }
    case 'saved':
      return { ...history, saved: action.doc };
  }
};

let counter = 0;
export const newId = (): string => `e${Date.now().toString(36)}${(counter++).toString(36)}`;

export const blankEdit = (target: { sel: string; label: string; scope: 'page' | 'template' | 'site'; page: string; tpl?: string }): Edit => ({
  id: newId(),
  scope: target.scope,
  page: target.page,
  tpl: target.tpl ?? '',
  sel: target.sel,
  label: target.label,
  styles: {},
  css: '',
});

/** Sets (or, with an empty value, removes) one CSS property of an edit for a device and optional state. */
export const setStyleProp = (
  edit: Edit,
  device: Device,
  state: State | 'normal',
  prop: string,
  value: string,
): Edit => {
  const update = (map: Partial<Record<Device, Record<string, string>>> | undefined) => {
    const next = { ...(map?.[device] ?? {}) };
    if (value.trim() === '') delete next[prop];
    else next[prop] = value;

    const all = { ...map, [device]: next };
    if (Object.keys(next).length === 0) delete all[device];
    return all;
  };

  if (state === 'normal') return { ...edit, styles: update(edit.styles) };

  const states = { ...edit.states };
  const updated = update(states[state]);
  if (Object.keys(updated).length === 0) delete states[state];
  else states[state] = updated;
  return { ...edit, states };
};
