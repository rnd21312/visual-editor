import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export type MenuItem =
  | 'separator'
  | { label: string; hint?: string; disabled?: boolean; danger?: boolean; onClick: () => void };

type Props = {
  x: number;
  y: number;
  /** Document the menu is shown in (the editor window or a detached panel's window). */
  doc: Document;
  items: MenuItem[];
  onClose: () => void;
};

/** Right-click menu. Closes on any outside click, Escape, scroll or window blur. */
export const ContextMenu = ({ x, y, doc, items, onClose }: Props) => {
  const ref = useRef<HTMLDivElement>(null);
  const [position, setPosition] = useState({ left: x, top: y });

  // Keep the menu inside the window.
  useLayoutEffect(() => {
    const menu = ref.current;
    const win = doc.defaultView;
    if (!menu || !win) return;
    const { width, height } = menu.getBoundingClientRect();
    setPosition({
      left: Math.max(4, Math.min(x, win.innerWidth - width - 4)),
      top: Math.max(4, Math.min(y, win.innerHeight - height - 4)),
    });
  }, [x, y, doc, items]);

  useEffect(() => {
    const win = doc.defaultView;
    if (!win) return;
    const outside = (event: Event) => {
      if (!ref.current?.contains(event.target as Node)) onClose();
    };
    const key = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    doc.addEventListener('pointerdown', outside, true);
    doc.addEventListener('keydown', key, true);
    win.addEventListener('blur', onClose);
    win.addEventListener('resize', onClose);
    return () => {
      doc.removeEventListener('pointerdown', outside, true);
      doc.removeEventListener('keydown', key, true);
      win.removeEventListener('blur', onClose);
      win.removeEventListener('resize', onClose);
    };
  }, [doc, onClose]);

  return createPortal(
    <div ref={ref} className="ve-menu" role="menu" style={position} onContextMenu={(event) => event.preventDefault()}>
      {items.map((item, index) =>
        item === 'separator' ? (
          <div key={index} className="ve-menu__sep" role="separator" />
        ) : (
          <button
            key={index}
            type="button"
            role="menuitem"
            disabled={item.disabled}
            className={item.danger ? 'is-danger' : ''}
            onClick={() => {
              onClose();
              item.onClick();
            }}
          >
            <span>{item.label}</span>
            {item.hint && <kbd>{item.hint}</kbd>}
          </button>
        ),
      )}
    </div>,
    doc.body,
  );
};
