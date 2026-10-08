import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';
import type { Pos } from '@/lib/visualCss';
import { dropFor, hitTest, type Drop } from './ops';
import type { Frame } from './types';

/** Rows of the Elements tree, so a drag can drop onto them (filled by the tree). */
export const treeRows = new Map<string, HTMLElement>();

export type DragPayload =
  | { kind: 'move'; source: HTMLElement; label: string }
  | { kind: 'new'; html: string; label: string; comp?: string };

type Props = {
  payload: DragPayload;
  frame: Frame;
  iframe: () => HTMLIFrameElement | null;
  onDrop: (drop: Drop, payload: DragPayload) => void;
  onCancel: () => void;
};

type Indicator = { left: number; top: number; width: number; height: number; pos: Pos; valid: boolean };

/**
 * Full-window shield shown while dragging: follows the pointer with a label, works out what is under it
 * (a page element or a tree row), draws where the drop would land, and reports the drop.
 */
export const DragLayer = ({ payload, frame, iframe, onDrop, onCancel }: Props) => {
  const [point, setPoint] = useState({ x: -200, y: -200 });
  const [indicator, setIndicator] = useState<Indicator | null>(null);
  const drop = useRef<Drop | null>(null);

  useEffect(() => {
    const source = payload.kind === 'move' ? payload.source : null;

    const target = (x: number, y: number): { drop: Drop; rect: DOMRect } | null => {
      const frameEl = iframe();
      const frameRect = frameEl?.getBoundingClientRect();

      if (frameRect && x >= frameRect.left && x <= frameRect.right && y >= frameRect.top && y <= frameRect.bottom) {
        const el = hitTest(frame.doc, x - frameRect.left, y - frameRect.top, source);
        if (!el) return null;
        const found = dropFor(el, x - frameRect.left, y - frameRect.top);
        const box = el.getBoundingClientRect();
        return {
          drop: found,
          rect: new DOMRect(frameRect.left + box.left, frameRect.top + box.top, box.width, box.height),
        };
      }

      for (const node of document.elementsFromPoint(x, y)) {
        const row = (node as HTMLElement).closest?.('[data-ve-row]') as HTMLElement | null;
        const el = row ? treeRows.get(row.dataset.veRow ?? '') : undefined;
        if (row && el && el !== source && !(source && source.contains(el))) {
          const box = row.getBoundingClientRect();
          const fraction = (y - box.top) / Math.max(box.height, 1);
          const pos: Pos = fraction < 0.25 ? 'before' : fraction > 0.75 ? 'after' : 'append';
          return { drop: { anchor: el, pos }, rect: box };
        }
      }
      return null;
    };

    const move = (event: PointerEvent) => {
      // The button was released somewhere we could not hear (outside the window): do not stay stuck.
      if (event.buttons === 0) {
        onCancel();
        return;
      }
      setPoint({ x: event.clientX, y: event.clientY });
      const found = target(event.clientX, event.clientY);
      drop.current = found?.drop ?? null;

      if (!found) {
        setIndicator(null);
        return;
      }
      const { rect } = found;
      const { pos } = found.drop;
      const thick = 3;
      setIndicator(
        pos === 'before'
          ? { left: rect.left, top: rect.top - thick / 2, width: rect.width, height: thick, pos, valid: true }
          : pos === 'after'
            ? { left: rect.left, top: rect.bottom - thick / 2, width: rect.width, height: thick, pos, valid: true }
            : { left: rect.left, top: rect.top, width: rect.width, height: rect.height, pos, valid: true },
      );
    };

    const up = () => {
      if (drop.current) onDrop(drop.current, payload);
      else onCancel();
    };
    const key = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onCancel();
    };

    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
    window.addEventListener('keydown', key);
    return () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
      window.removeEventListener('keydown', key);
    };
  }, [payload, frame, iframe, onDrop, onCancel]);

  return (
    <div className="ve-dragshield">
      {indicator && (
        <div
          className={`ve-drop ve-drop--${indicator.pos === 'append' ? 'inside' : 'line'}`}
          style={{ left: indicator.left, top: indicator.top, width: indicator.width, height: indicator.height }}
        />
      )}
      <div className="ve-ghost" style={{ left: point.x + 14, top: point.y + 14 }}>
        {payload.label}
        {indicator?.pos === 'append' && ' → inside'}
      </div>
    </div>
  );
};

/**
 * Starts a drag from a pointerdown once the pointer has travelled a few pixels (so plain clicks still
 * click). `begin` is called with the pointer's first position.
 */
export const armDrag = (event: ReactPointerEvent, begin: () => void): void => {
  if (event.button !== 0) return;
  const startX = event.clientX;
  const startY = event.clientY;

  const move = (next: PointerEvent) => {
    if (Math.hypot(next.clientX - startX, next.clientY - startY) < 6) return;
    cleanup();
    begin();
  };
  const cleanup = () => {
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', cleanup);
  };

  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', cleanup);
};
