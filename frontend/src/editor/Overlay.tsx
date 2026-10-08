import { useEffect, useRef, type PointerEvent as ReactPointerEvent } from 'react';
import { describe } from './selector';

type Props = {
  hovered: Element | null;
  selected: Element | null;
  /** Extra elements picked with Ctrl/Cmd-click. */
  multi: Element[];
  /** Pointer went down on the move handle of the selected element. */
  onHandleDown: (event: ReactPointerEvent) => void;
};

const px = (value: string): number => Number.parseFloat(value) || 0;

type Layers = { box: HTMLDivElement; margin: HTMLDivElement; padding: HTMLDivElement; label: HTMLElement; text: HTMLElement };

/** Positions one highlight on top of an element of the preview (coordinates are the iframe's viewport). */
const paint = (layers: Layers | null, el: Element | null, detailed: boolean): void => {
  if (!layers) return;
  const { box, margin, padding, label, text } = layers;

  if (!el || !el.isConnected) {
    box.style.display = 'none';
    return;
  }

  const win = el.ownerDocument.defaultView;
  if (!win) return;
  const rect = el.getBoundingClientRect();
  if (rect.width === 0 && rect.height === 0) {
    box.style.display = 'none'; // <head> tags, display:none elements: nothing to outline
    return;
  }
  const cs = win.getComputedStyle(el);

  box.style.display = 'block';
  box.style.transform = `translate(${rect.left}px, ${rect.top}px)`;
  box.style.width = `${rect.width}px`;
  box.style.height = `${rect.height}px`;

  if (detailed) {
    const m = [cs.marginTop, cs.marginRight, cs.marginBottom, cs.marginLeft].map(px);
    const p = [cs.paddingTop, cs.paddingRight, cs.paddingBottom, cs.paddingLeft].map(px);
    const b = [cs.borderTopWidth, cs.borderRightWidth, cs.borderBottomWidth, cs.borderLeftWidth].map(px);

    margin.style.display = 'block';
    margin.style.inset = `${-(m[0] ?? 0)}px ${-(m[1] ?? 0)}px ${-(m[2] ?? 0)}px ${-(m[3] ?? 0)}px`;
    margin.style.borderWidth = `${m[0]}px ${m[1]}px ${m[2]}px ${m[3]}px`;

    padding.style.display = 'block';
    padding.style.inset = `${b[0]}px ${b[1]}px ${b[2]}px ${b[3]}px`;
    padding.style.borderWidth = `${p[0]}px ${p[1]}px ${p[2]}px ${p[3]}px`;
  } else {
    margin.style.display = 'none';
    padding.style.display = 'none';
  }

  text.textContent = `${describe(el, 3)}  ${Math.round(rect.width)} × ${Math.round(rect.height)}`;
  label.style.top = rect.top < 26 ? `${rect.height + 4}px` : '-24px';
};

const buildLayers = (host: HTMLDivElement | null): Layers | null => {
  if (!host) return null;
  const [margin, padding] = Array.from(host.querySelectorAll('div'));
  const label = host.querySelector<HTMLElement>('.ve-label');
  const text = host.querySelector<HTMLElement>('.ve-label__text');
  return margin && padding && label && text ? { box: host, margin, padding, label, text } : null;
};

/** Hover and selection boxes drawn over the preview, like the highlight in browser dev tools. */
export const Overlay = ({ hovered, selected, multi, onHandleDown }: Props) => {
  const hoverRef = useRef<HTMLDivElement>(null);
  const selectedRef = useRef<HTMLDivElement>(null);
  const multiRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const hoverLayers = buildLayers(hoverRef.current);
    const selectedLayers = buildLayers(selectedRef.current);
    let frame = 0;

    const extra = multiRef.current;

    const tick = () => {
      paint(hoverLayers, hovered && hovered !== selected ? hovered : null, true);
      paint(selectedLayers, selected, true);

      if (extra) {
        while (extra.children.length < multi.length) extra.appendChild(document.createElement('div')).className = 've-box ve-box--multi';
        while (extra.children.length > multi.length) extra.lastElementChild?.remove();
        multi.forEach((el, index) => {
          const box = extra.children[index] as HTMLElement | undefined;
          if (!box) return;
          const rect = el.isConnected ? el.getBoundingClientRect() : null;
          if (!rect || (rect.width === 0 && rect.height === 0)) {
            box.style.display = 'none';
            return;
          }
          box.style.display = 'block';
          box.style.transform = `translate(${rect.left}px, ${rect.top}px)`;
          box.style.width = `${rect.width}px`;
          box.style.height = `${rect.height}px`;
        });
      }
      frame = requestAnimationFrame(tick);
    };
    tick();

    return () => cancelAnimationFrame(frame);
  }, [hovered, selected, multi]);

  return (
    <div className="ve-overlay" aria-hidden="true">
      <div ref={multiRef} />
      <div ref={hoverRef} className="ve-box ve-box--hover">
        <div className="ve-margin" />
        <div className="ve-padding" />
        <span className="ve-label">
          <span className="ve-label__text" />
        </span>
      </div>
      <div ref={selectedRef} className="ve-box ve-box--selected">
        <div className="ve-margin" />
        <div className="ve-padding" />
        <span className="ve-label">
          <span
            className="ve-handle"
            title="Drag to move (Alt-drag to nudge freely)"
            onPointerDown={onHandleDown}
          >
            ⠿
          </span>
          <span className="ve-label__text" />
        </span>
      </div>
    </div>
  );
};
