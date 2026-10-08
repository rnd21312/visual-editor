import { useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { useEditor } from '../context';
import { armDrag, treeRows } from '../DragLayer';
import { appliesHere } from '@/lib/visualCss';
import { describe, isRendered, isTreeNode, textSnippet } from '../selector';

const KEY = 'sve-show-hidden';

const inHead = (el: Element): boolean => el.localName === 'head' || el.closest('head') !== null;

/** Child elements to list: everything in <head>, and in <body> only what is displayed unless asked otherwise. */
const children = (el: Element, showHidden: boolean): HTMLElement[] =>
  Array.from(el.children).filter(
    (child) => isTreeNode(child) && (showHidden || inHead(child) || isRendered(child)),
  ) as HTMLElement[];

/** Attribute that tells head tags apart (meta name, link rel, script src …). */
const hint = (el: Element): string => {
  const value = el.getAttribute('name') ?? el.getAttribute('property') ?? el.getAttribute('rel') ?? el.getAttribute('src') ?? el.getAttribute('href') ?? '';
  return value.length > 34 ? `${value.slice(0, 34)}…` : value;
};

/** Expands the first levels so the page structure is visible straight away. */
const initialOpen = (root: Element, showHidden: boolean, depth = 5): Set<Element> => {
  const open = new Set<Element>();
  const walk = (el: Element, level: number) => {
    if (level >= depth || el.localName === 'head') return; // <head> stays collapsed until asked for
    open.add(el);
    children(el, showHidden).forEach((child) => walk(child, level + 1));
  };
  walk(root, 0);
  return open;
};

const keys = new WeakMap<Element, number>();
let keySeed = 0;
const nodeKey = (el: Element): number => {
  let key = keys.get(el);
  if (key === undefined) {
    key = ++keySeed;
    keys.set(el, key);
  }
  return key;
};

type RowProps = {
  el: HTMLElement;
  depth: number;
  open: Set<Element>;
  edited: Set<Element>;
  showHidden: boolean;
  toggle: (el: Element) => void;
};

const TreeRow = ({ el, depth, open, edited, showHidden, toggle }: RowProps) => {
  const { selected, multi, hovered, select, hover, actions } = useEditor();
  const rowRef = useRef<HTMLDivElement>(null);
  const kids = children(el, showHidden);
  const isOpen = open.has(el);
  const isSelected = selected === el;
  const hidden = !inHead(el) && !isRendered(el);

  useEffect(() => {
    if (isSelected) rowRef.current?.scrollIntoView({ block: 'nearest' });
  }, [isSelected]);

  // Rows register themselves so a drag can drop onto them.
  const rowKey = String(nodeKey(el));
  useEffect(() => {
    if (rowRef.current) treeRows.set(rowKey, el);
    return () => {
      treeRows.delete(rowKey);
    };
  }, [rowKey, el]);

  const text = kids.length === 0 ? textSnippet(el) : '';
  const attribute = ['meta', 'link', 'script', 'source', 'iframe', 'a'].includes(el.localName) ? hint(el) : '';
  const classes = Array.from(el.classList)
    .filter((cls) => !cls.includes(':') && !cls.includes('['))
    .slice(0, 3)
    .join(' ');
  const draggable = !['html', 'head', 'body'].includes(el.localName);

  return (
    <>
      <div
        ref={rowRef}
        data-ve-row={rowKey}
        onPointerDown={(event) =>
          draggable &&
          event.button === 0 &&
          armDrag(event, () => actions.startDrag(event, { kind: 'move', source: el, label: describe(el) }))
        }
        className={`ve-node ${isSelected ? 'is-selected' : ''} ${multi.includes(el) ? 'is-multi' : ''} ${hovered === el ? 'is-hovered' : ''} ${hidden ? 'is-hidden' : ''}`}
        style={{ paddingInlineStart: 6 + depth * 12 }}
        onClick={(event) => (event.ctrlKey || event.metaKey ? actions.toggleMulti(el) : select(el))}
        onContextMenu={(event) => {
          event.preventDefault();
          actions.openMenu(el, event.clientX, event.clientY, event.currentTarget.ownerDocument);
        }}
        onMouseEnter={() => hover(el)}
        onMouseLeave={() => hover(null)}
        role="treeitem"
        aria-selected={isSelected}
        aria-expanded={kids.length > 0 ? isOpen : undefined}
        title={hidden ? 'Not displayed at this screen size' : undefined}
      >
        <button
          type="button"
          className="ve-twist"
          tabIndex={-1}
          aria-label={isOpen ? 'Collapse' : 'Expand'}
          onClick={(event) => {
            event.stopPropagation();
            toggle(el);
          }}
          style={{ visibility: kids.length > 0 ? 'visible' : 'hidden' }}
        >
          {isOpen ? '▾' : '▸'}
        </button>
        <span className="ve-node__tag">{el.localName}</span>
        {el.id && <span className="ve-node__id">#{el.id}</span>}
        {classes && <span className="ve-node__cls">.{classes.replace(/ /g, '.')}</span>}
        {attribute && <span className="ve-node__attr">{attribute}</span>}
        {text && <span className="ve-node__text">“{text}”</span>}
        {edited.has(el) && <span className="ve-dot" title="Has changes" />}
      </div>
      {isOpen &&
        kids.map((child) => (
          <TreeRow key={nodeKey(child)} el={child} depth={depth + 1} open={open} edited={edited} showHidden={showHidden} toggle={toggle} />
        ))}
    </>
  );
};

/** The page's elements as a tree — click to select, right-click for actions, drag to reorder. */
export const Tree = () => {
  const { frame, selected, doc, page, template, version } = useEditor();
  const [, refresh] = useReducer((n: number) => n + 1, 0);
  const [open, setOpen] = useState<Set<Element>>(new Set());
  const [showHidden, setShowHidden] = useState<boolean>(() => {
    try {
      return window.localStorage.getItem(KEY) === '1';
    } catch {
      return false;
    }
  });

  const changeHidden = (next: boolean) => {
    setShowHidden(next);
    try {
      window.localStorage.setItem(KEY, next ? '1' : '0');
    } catch {
      // Not remembered in private mode.
    }
  };

  // Re-read the DOM when React changes it (throttled).
  useEffect(() => {
    if (!frame) return;
    let timer = 0;
    const observer = new frame.win.MutationObserver(() => {
      if (timer) return;
      timer = window.setTimeout(() => {
        timer = 0;
        refresh();
      }, 250);
    });
    observer.observe(frame.doc.documentElement, { childList: true, subtree: true });
    setOpen(initialOpen(frame.doc.documentElement, showHidden));
    return () => {
      observer.disconnect();
      window.clearTimeout(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [frame]);

  // Open the path down to the selected element.
  useEffect(() => {
    if (!selected) return;
    setOpen((current) => {
      const next = new Set(current);
      for (let el = selected.parentElement; el; el = el.parentElement) next.add(el);
      return next;
    });
  }, [selected]);

  const edited = useMemo(() => {
    const set = new Set<Element>();
    if (!frame) return set;
    for (const edit of doc.edits) {
      if (!appliesHere(edit, page, template.key)) continue;
      try {
        frame.doc.querySelectorAll(edit.sel).forEach((el) => set.add(el));
      } catch {
        // invalid selector while typing
      }
    }
    return set;
    // `version` changes whenever the preview DOM does.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [frame, doc, page, version]);

  const toggle = (el: Element) =>
    setOpen((current) => {
      const next = new Set(current);
      if (!next.delete(el)) next.add(el);
      return next;
    });

  if (!frame) return <p className="ve-note">Loading the page…</p>;

  const root = frame.doc.documentElement as HTMLElement;

  return (
    <>
      <label className="ve-tree__filter" title="Themes often ship alternative headers and menus that are hidden by CSS. Hide them here.">
        <input type="checkbox" checked={!showHidden} onChange={(event) => changeHidden(!event.target.checked)} />
        Only elements displayed at this screen size
      </label>
      <div className="ve-tree" role="tree" aria-label="Page elements">
        <TreeRow el={root} depth={0} open={open} edited={edited} showHidden={showHidden} toggle={toggle} />
      </div>
    </>
  );
};
