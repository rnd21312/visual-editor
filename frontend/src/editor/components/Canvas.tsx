import { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowUp, Copy, GripVertical, Trash2, ArrowDown, CornerLeftUp } from 'lucide-react';
import { useStore } from '../store';
import { attachFrame, frameDoc, onFrameLoad, syncFrame, findAgain } from '../frame';
import { duplicateSelected, moveSelected, moveSibling, removeSelected, reselect, select, selectParent, startInlineEdit, saveSelectionAsComponent, wrapSelected } from '../actions';
import { describe } from '../../shared/selector';
import { ContextMenu, type MenuItem } from './ui';
import { t } from '../i18n';

type Box = { top: number; left: number; width: number; height: number };

const WIDTHS = { desktop: 0, tablet: 820, mobile: 390 };

const previewUrl = (url: string, arg: string, extra: Record<string, string> = {}) => {
  try {
    const u = new URL(url);
    u.searchParams.set(arg, '1');
    Object.entries(extra).forEach(([k, v]) => u.searchParams.set(k, v));
    return u.toString();
  } catch {
    return url;
  }
};

export const Canvas = () => {
  const url = useStore((s) => s.url);
  const frameKey = useStore((s) => s.frameKey);
  const device = useStore((s) => s.device);
  const cfg = useStore((s) => s.cfg);
  const selected = useStore((s) => s.selected);
  const loading = useStore((s) => s.loading);
  const interact = useStore((s) => s.interact);
  const liveNote = useStore((s) => s.liveNote);
  const doc = useStore((s) => s.doc);
  const ctx = useStore((s) => s.ctx);
  const tplPreview = useStore((s) => s.tplPreview);

  const iframe = useRef<HTMLIFrameElement>(null);
  const stage = useRef<HTMLDivElement>(null);
  const hoverEl = useRef<Element | null>(null);
  const [hover, setHover] = useState<{ box: Box; label: string } | null>(null);
  const [selBox, setSelBox] = useState<Box | null>(null);
  const [spacing, setSpacing] = useState<{ m: Box[]; p: Box[] }>({ m: [], p: [] });
  const [menu, setMenu] = useState<{ x: number; y: number } | null>(null);
  const [drop, setDrop] = useState<{ box: Box; target: Element; pos: 'before' | 'after' | 'append' } | null>(null);
  const scrollY = useRef(0);

  const src = previewUrl(url, cfg.previewArg, tplPreview ? { sve_tpl: String(tplPreview) } : {});

  // Keep the preview in sync with the working document.
  useEffect(() => {
    if (!ctx) return;
    if (syncFrame(doc, ctx) === 'reload') {
      scrollY.current = frameDoc()?.defaultView?.scrollY ?? 0;
      useStore.getState().reload();
    }
  }, [doc, ctx]);

  const rectOf = useCallback((el: Element): Box | null => {
    const frame = iframe.current;
    if (!frame || !el.isConnected) return null;
    const r = el.getBoundingClientRect();
    return { top: r.top, left: r.left, width: r.width, height: r.height };
  }, []);

  // Overlay positions follow scrolling, resizing and layout changes (state only changes when boxes move).
  useEffect(() => {
    let raf = 0;
    let lastSel = '';
    let lastHover = '';
    const key = (b: Box | null) => (b ? `${Math.round(b.top)},${Math.round(b.left)},${Math.round(b.width)},${Math.round(b.height)}` : '');
    const loop = () => {
      const sel = useStore.getState().selected;
      let selKey = '';
      if (sel) {
        if (!sel.el.isConnected) {
          const again = findAgain(sel.sel);
          if (again) useStore.setState({ selected: { ...sel, el: again } });
        }
        const b = rectOf(sel.el);
        const win = sel.el.ownerDocument.defaultView;
        let m: Box[] = [];
        let p: Box[] = [];
        if (win && b) {
          const cs = win.getComputedStyle(sel.el);
          const n = (v: string) => parseFloat(v) || 0;
          const [mt, mr, mb, ml] = [cs.marginTop, cs.marginRight, cs.marginBottom, cs.marginLeft].map(n);
          const [pt, pr, pb, pl] = [cs.paddingTop, cs.paddingRight, cs.paddingBottom, cs.paddingLeft].map(n);
          m = [
            { top: b.top - mt, left: b.left, width: b.width, height: mt },
            { top: b.top + b.height, left: b.left, width: b.width, height: mb },
            { top: b.top, left: b.left - ml, width: ml, height: b.height },
            { top: b.top, left: b.left + b.width, width: mr, height: b.height },
          ];
          p = [
            { top: b.top, left: b.left, width: b.width, height: pt },
            { top: b.top + b.height - pb, left: b.left, width: b.width, height: pb },
            { top: b.top, left: b.left, width: pl, height: b.height },
            { top: b.top, left: b.left + b.width - pr, width: pr, height: b.height },
          ];
        }
        selKey = key(b) + '|' + m.map(key).join(';') + '|' + p.map(key).join(';');
        if (selKey !== lastSel) {
          lastSel = selKey;
          setSelBox(b);
          setSpacing({ m, p });
        }
      } else if (lastSel !== '') {
        lastSel = '';
        setSelBox(null);
      }
      const h = hoverEl.current ? rectOf(hoverEl.current) : null;
      const hk = key(h);
      if (hk !== lastHover) {
        lastHover = hk;
        setHover(h && hoverEl.current ? { box: h, label: describe(hoverEl.current) } : null);
      }
      raf = requestAnimationFrame(loop);
    };
    raf = requestAnimationFrame(loop);
    return () => cancelAnimationFrame(raf);
  }, [rectOf]);

  const onLoad = useCallback(() => {
    const frame = iframe.current;
    if (!frame) return;
    attachFrame(frame);
    const c = onFrameLoad();
    const d = frame.contentDocument;
    const st = useStore.getState();
    useStore.setState({ ctx: c, loading: false });
    if (!d || !c) {
      if (d && !c) st.toast(t('This page is not part of this site, or the editor preview could not start here.'), 'error');
      return;
    }
    // Remember where we are.
    try {
      const u = new URL(frame.contentWindow!.location.href);
      u.searchParams.delete(cfg.previewArg);
      u.searchParams.delete('sve_tpl');
      if (u.toString() !== st.url) useStore.setState({ url: u.toString() });
    } catch {
      /* cross-origin navigation */
    }
    // Load the builder layouts this page is made of.
    [c.builder.post?.id, c.builder.template?.id, c.builder.header, c.builder.footer].forEach((id) => id && void st.loadSource(id));
    syncFrame(st.doc, c);
    d.defaultView?.scrollTo(0, scrollY.current);
    scrollY.current = 0;
    reselect();

    const designMode = () => !useStore.getState().interact;
    d.documentElement.classList.toggle('sve-design', designMode());

    d.addEventListener('mouseover', (e) => {
      if (!designMode()) return;
      const el = e.target as Element;
      hoverEl.current = el;
      setHover({ box: rectOf(el) ?? { top: 0, left: 0, width: 0, height: 0 }, label: describe(el) });
    });
    d.addEventListener('mouseleave', () => {
      hoverEl.current = null;
      setHover(null);
    });
    d.addEventListener(
      'click',
      (e) => {
        if (!designMode()) {
          // Interact mode: follow links inside the site in the editor.
          return;
        }
        const el = e.target as Element;
        if ((el as HTMLElement).isContentEditable) return;
        e.preventDefault();
        e.stopPropagation();
        select(el);
      },
      true,
    );
    d.addEventListener('dblclick', (e) => {
      if (!designMode()) return;
      e.preventDefault();
      startInlineEdit(e.target as HTMLElement);
    });
    d.addEventListener('submit', (e) => designMode() && e.preventDefault(), true);
    d.addEventListener('contextmenu', (e) => {
      if (!designMode()) return;
      e.preventDefault();
      select(e.target as Element);
      const r = frame.getBoundingClientRect();
      setMenu({ x: r.left + e.clientX, y: r.top + e.clientY });
    });
    d.addEventListener('keydown', (e) => {
      const el = e.target as HTMLElement;
      if (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
      const copy = new KeyboardEvent('keydown', { key: e.key, code: e.code, ctrlKey: e.ctrlKey, metaKey: e.metaKey, shiftKey: e.shiftKey, altKey: e.altKey, cancelable: true });
      window.dispatchEvent(copy);
      if (copy.defaultPrevented) e.preventDefault();
    });
    d.addEventListener('scroll', () => setMenu(null), { passive: true });
  }, [cfg.previewArg, rectOf]);

  useEffect(() => {
    frameDoc()?.documentElement.classList.toggle('sve-design', !interact);
    if (interact) {
      hoverEl.current = null;
      setHover(null);
    }
  }, [interact]);

  // Drag the selected element to move it.
  const startDrag = (e: React.PointerEvent) => {
    e.preventDefault();
    const frame = iframe.current;
    const sel = useStore.getState().selected;
    if (!frame || !sel) return;
    const fr = frame.getBoundingClientRect();
    let current: typeof drop = null;
    const onMove = (ev: PointerEvent) => {
      const d = frame.contentDocument;
      if (!d) return;
      const x = ev.clientX - fr.left;
      const y = ev.clientY - fr.top;
      let el = d.elementFromPoint(x, y);
      while (el && (el === sel.el || sel.el.contains(el))) el = el.parentElement;
      if (!el || el.localName === 'html' || el.localName === 'body') {
        current = null;
        setDrop(null);
        return;
      }
      const r = el.getBoundingClientRect();
      const empty = el.children.length === 0 && !/^(img|input|br|hr|svg|video|iframe)$/.test(el.localName);
      const pos: 'before' | 'after' | 'append' = empty && y > r.top + r.height * 0.3 && y < r.bottom - r.height * 0.3 ? 'append' : y < r.top + r.height / 2 ? 'before' : 'after';
      const box = pos === 'append' ? { top: r.top + r.height / 2, left: r.left + 8, width: r.width - 16, height: 3 } : { top: pos === 'before' ? r.top - 2 : r.bottom - 1, left: r.left, width: r.width, height: 3 };
      current = { box, target: el, pos };
      setDrop(current);
    };
    const onUp = () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
      setDrop(null);
      if (current) moveSelected(current.target, current.pos);
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
  };

  const width = WIDTHS[device];
  const builder = selected && selected.owner.kind !== 'theme';
  const items: MenuItem[] = [
    { label: t('Select parent'), kbd: 'Esc', onClick: selectParent },
    { label: t('Edit text'), kbd: t('Double click'), onClick: () => selected && startInlineEdit(selected.el as HTMLElement) },
    'sep',
    { label: t('Duplicate'), kbd: 'Ctrl+D', onClick: duplicateSelected },
    { label: t('Move up'), onClick: () => moveSibling(-1) },
    { label: t('Move down'), onClick: () => moveSibling(1) },
    { label: t('Wrap in container'), onClick: () => wrapSelected() },
    {
      label: t('Save as component…'),
      onClick: () => {
        const name = window.prompt(t('Component name'), selected?.label ?? 'Component');
        if (name) saveSelectionAsComponent(name);
      },
    },
    { label: t('Hide on mobile'), onClick: () => selected && useStore.getState().updateEdit(selected.sel, (e) => (e.hide = { ...(e.hide ?? {}), mobile: !e.hide?.mobile })) },
    'sep',
    { label: t('Delete'), kbd: 'Del', danger: true, onClick: removeSelected },
  ];

  return (
    <div className="ve-canvas" onMouseLeave={() => setHover(null)}>
      <div className={'ve-stage' + (width ? ' framed' : '')} ref={stage} style={{ width: width ? width : '100%' }}>
        <iframe key={frameKey} ref={iframe} src={src} title={t('Preview')} onLoad={onLoad} />
        <div className="ve-overlay">
          {!interact && hover && hover.box.width > 0 && (!selBox || hoverEl.current !== selected?.el) && (
            <div className="ve-hover" style={{ top: hover.box.top, left: hover.box.left, width: hover.box.width, height: hover.box.height }}>
              {hover.box.top > 22 && <span className="tag">{hover.label}</span>}
            </div>
          )}
          {!interact && selBox && selected && (
            <>
              {spacing.m.map((b, i) => b.width > 0 && b.height > 0 && <div key={'m' + i} className="ve-margin" style={b} />)}
              {spacing.p.map((b, i) => b.width > 0 && b.height > 0 && <div key={'p' + i} className="ve-padding" style={b} />)}
              <div className={'ve-sel' + (builder ? ' builder' : '')} style={{ top: selBox.top, left: selBox.left, width: selBox.width, height: selBox.height }}>
                <span className="tag" style={selBox.top < 22 ? { top: 0 } : undefined}>
                  <span style={{ padding: '0 4px 0 0' }}>{selected.label}</span>
                  <button title={t('Drag to move')} onPointerDown={startDrag} style={{ cursor: 'grab' }}>
                    <GripVertical size={12} />
                  </button>
                  <button title={t('Select parent')} onClick={selectParent}>
                    <CornerLeftUp size={12} />
                  </button>
                  <button title={t('Move up')} onClick={() => moveSibling(-1)}>
                    <ArrowUp size={12} />
                  </button>
                  <button title={t('Move down')} onClick={() => moveSibling(1)}>
                    <ArrowDown size={12} />
                  </button>
                  <button title={t('Duplicate')} onClick={duplicateSelected}>
                    <Copy size={12} />
                  </button>
                  <button title={t('Delete')} onClick={removeSelected}>
                    <Trash2 size={12} />
                  </button>
                </span>
              </div>
            </>
          )}
          {drop && <div className="ve-drop" style={{ top: drop.box.top, left: drop.box.left, width: drop.box.width }} />}
          {liveNote && selBox && (
            <div className="ve-ai-note" style={{ top: Math.min(selBox.top + selBox.height + 8, (stage.current?.clientHeight ?? 600) - 80), left: Math.max(8, selBox.left) }}>
              🤖 {liveNote.message || t('The AI is pointing at this element.')}
            </div>
          )}
        </div>
        {loading && <div className="ve-loading" />}
      </div>
      {menu && selected && <ContextMenu x={menu.x} y={menu.y} items={items} onClose={() => setMenu(null)} />}
    </div>
  );
};
