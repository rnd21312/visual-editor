import { createContext, useContext, type PointerEvent as ReactPointerEvent } from 'react';
import type { ComponentKind, Device, Edit, Pos, State, VisualDoc } from '@/lib/visualCss';
import type { DragPayload } from './DragLayer';
import type { EditorConfig, Frame, Target, TemplateInfo } from './types';

export type Actions = {
  deselect: () => void;
  selectParent: () => void;
  duplicate: () => void;
  remove: () => void;
  /** Swaps the element with its previous (-1) or next (1) sibling. */
  moveBy: (direction: -1 | 1) => void;
  /** Changes the element's tag (div → section, h2 → h3 …). */
  retag: (tag: string) => void;
  /** Inserts markup next to the selection (or at the end of the page content when nothing is selected). */
  insert: (html: string, label: string, comp?: string, exec?: boolean) => void;
  /** Inserts embed code or JSON-LD (kinds that need special handling). */
  insertCode: (kind: ComponentKind, code: string, label: string) => void;
  startDrag: (event: ReactPointerEvent, payload: DragPayload) => void;
  insertAt: Pos;
  setInsertAt: (pos: Pos) => void;

  copy: () => void;
  cut: () => void;
  paste: (pos: Pos) => void;
  hasClipboard: boolean;
  copyStyle: () => void;
  pasteStyle: () => void;
  hasStyleClipboard: boolean;
  group: () => void;
  ungroup: () => void;
  editText: () => void;
  copySelector: () => void;
  resetChanges: () => void;
  saveAsComponent: (name?: string) => void;

  /** Edit the rule of a CSS class (every element that has it) instead of this one element. */
  editClass: (name: string) => void;
  addClass: (name: string) => void;
  removeClass: (name: string) => void;

  /** Ctrl/Cmd-click: add or remove an element from the selection. */
  toggleMulti: (el: HTMLElement) => void;
  openMenu: (el: HTMLElement, x: number, y: number, doc: Document) => void;
  /** Elements the actions apply to: the selection plus Ctrl-clicked ones, in page order. */
  targets: () => HTMLElement[];
};

export type EditorContextValue = {
  config: EditorConfig;
  doc: VisualDoc;
  /** Page key of the page in the preview. */
  page: string;
  /** The theme template rendering it (one for all products, all posts…). */
  template: TemplateInfo;
  device: Device;
  /** Which state the Style panel edits: the normal look, or :hover / :focus / :active. */
  state: State | 'normal';
  setState: (state: State | 'normal') => void;
  frame: Frame | null;
  selected: HTMLElement | null;
  /** Extra elements selected with Ctrl/Cmd-click. */
  multi: HTMLElement[];
  hovered: HTMLElement | null;
  /** Bumps whenever the preview DOM or the edits changed (re-read computed styles). */
  version: number;
  /** Where a new edit for the selected element would go. */
  target: Target | null;
  /** Edits that apply to the selected element. */
  matching: Edit[];
  activeEdit: Edit | null;
  select: (el: HTMLElement | null) => void;
  hover: (el: HTMLElement | null) => void;
  setActiveId: (id: string | null) => void;
  /** Changes the active edit (creating it on first use). `key` groups keystrokes into one undo step. */
  patch: (fn: (edit: Edit) => Edit, key: string) => void;
  patchDoc: (fn: (doc: VisualDoc) => VisualDoc, key: string) => void;
  removeEdit: (id: string) => void;
  setScope: (scope: 'page' | 'template' | 'site') => void;
  actions: Actions;
};

export const EditorContext = createContext<EditorContextValue | null>(null);

export const useEditor = (): EditorContextValue => {
  const value = useContext(EditorContext);
  if (!value) throw new Error('useEditor outside <EditorContext>');
  return value;
};
