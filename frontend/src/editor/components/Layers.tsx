import { useEffect, useMemo, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useStore } from '../store';
import { frameDoc } from '../frame';
import { select, moveSelected } from '../actions';
import { describe } from '../../shared/selector';
import { t } from '../i18n';

const SKIP = new Set(['script', 'style', 'link', 'meta', 'noscript', 'template', 'br', 'wbr', 'path', 'g', 'defs', 'use', 'circle', 'rect', 'line', 'polyline', 'polygon', 'stop', 'source', 'track']);

const children = (el: Element) => Array.from(el.children).filter((c) => !SKIP.has(c.localName) && c.id !== 'wpadminbar' && !c.closest('#wpadminbar'));

const ownText = (el: Element) =>
  Array.from(el.childNodes)
    .filter((n) => n.nodeType === 3)
    .map((n) => n.nodeValue ?? '')
    .join(' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 40);

export const Layers = () => {
  const selected = useStore((s) => s.selected);
  const frameKey = useStore((s) => s.frameKey);
  const loading = useStore((s) => s.loading);
  const doc = useStore((s) => s.doc);
  const [tick, setTick] = useState(0);
  const [open, setOpen] = useState<Set<Element>>(() => new Set());
  const [dragOver, setDragOver] = useState<Element | null>(null);

  // Re-read the tree when the page changes.
  useEffect(() => {
    const d = frameDoc();
    if (!d?.body || loading) return;
    let timer = 0;
    const obs = new MutationObserver(() => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => setTick((x) => x + 1), 300);
    });
    obs.observe(d.body, { childList: true, subtree: true });
    setTick((x) => x + 1);
    return () => {
      obs.disconnect();
      window.clearTimeout(timer);
    };
  }, [frameKey, loading]);

  // Unfold the path to the selection.
  useEffect(() => {
    if (!selected) return;
    const next = new Set(open);
    for (let p = selected.el.parentElement; p; p = p.parentElement) next.add(p);
    setOpen(next);
    window.setTimeout(() => document.querySelector('.ve-node.on')?.scrollIntoView({ block: 'nearest' }), 30);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected?.el]);

  const edited = useMemo(() => new Set(doc.edits.map((e) => e.sel)), [doc]);
  const body = frameDoc()?.body;
  if (!body) return <div className="ve-empty">{t('Loading…')}</div>;

  const toggle = (el: Element) => {
    const next = new Set(open);
    if (next.has(el)) next.delete(el);
    else next.add(el);
    setOpen(next);
  };

  const rows: JSX.Element[] = [];
  const walk = (el: Element, depth: number) => {
    const kids = children(el);
    const isOpen = open.has(el) || depth < 1;
    const sid = el.getAttribute('data-sid');
    const sel = sid ? `[data-sid="${sid}"]` : null;
    const hidden = el.ownerDocument.defaultView?.getComputedStyle(el).display === 'none';
    rows.push(
      <div
        key={rows.length + ':' + tick}
        className={'ve-node' + (selected?.el === el ? ' on' : '') + (dragOver === el ? ' drop' : '')}
        style={{ paddingInlineStart: 4 + depth * 12 }}
        onClick={() => select(el)}
        draggable={selected?.el === el}
        onDragOver={(e) => {
          e.preventDefault();
          setDragOver(el);
        }}
        onDragLeave={() => setDragOver(null)}
        onDrop={(e) => {
          e.preventDefault();
          setDragOver(null);
          moveSelected(el, e.altKey ? 'append' : 'after');
        }}
        title={t('Drag the selected layer onto another to move it after it (Alt: inside)')}
      >
        <span className="tw" onClick={(e) => { e.stopPropagation(); toggle(el); }}>
          {kids.length ? isOpen ? <ChevronDown size={12} /> : <ChevronRight size={12} /> : null}
        </span>
        <span className="nm">{el.getAttribute('data-sve-tag') ? '⟨' + el.getAttribute('data-sve-tag') + '⟩' : el.getAttribute('data-sve-instance') ? '◇ ' + t('component') : describe(el)}</span>
        <span className="tx">{ownText(el)}</span>
        <span className="mk">
          {sid && <i className="b" title={t('Builder element')} />}
          {sel && edited.has(sel) && <i title={t('Styled')} />}
          {hidden && <i className="h" title={t('Hidden')} />}
        </span>
      </div>,
    );
    if (isOpen && depth < 40) kids.forEach((k) => walk(k, depth + 1));
  };
  children(body).forEach((c) => walk(c, 0));

  return <div className="ve-tree">{rows}</div>;
};
