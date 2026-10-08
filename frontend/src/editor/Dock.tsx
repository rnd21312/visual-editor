import { useCallback, useRef, useState, type PointerEvent as ReactPointerEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { MAX_WIDTH, MIN_WIDTH, type PanelState, type Side } from './layout';

/* ---------- a browser window that hosts a panel ---------- */

type PopoutOptions = { name: string; title: string; onClosed: () => void; onKey: (event: KeyboardEvent) => void };

/**
 * Opens a real browser window and gives back an element React can render into (a portal), so a panel keeps
 * working — same state, same shortcuts — while living on another monitor.
 */
const usePopout = ({ name, title, onClosed, onKey }: PopoutOptions) => {
  const [container, setContainer] = useState<HTMLElement | null>(null);
  const win = useRef<Window | null>(null);
  const handlers = useRef({ onClosed, onKey });
  handlers.current = { onClosed, onKey };

  const close = useCallback(() => {
    win.current?.close();
    win.current = null;
    setContainer(null);
  }, []);

  /** Must run inside a click handler, or popup blockers refuse it. Returns false when blocked. */
  const open = useCallback((): boolean => {
    const popup = window.open('', `sve-${name}`, 'popup=yes,width=420,height=800,left=80,top=60');
    if (!popup) return false;

    const doc = popup.document;
    doc.title = title;
    doc.head.replaceChildren();
    document.head.querySelectorAll('link[rel="stylesheet"], style').forEach((node) => {
      const copy = node.cloneNode(true) as HTMLElement;
      if (node instanceof HTMLLinkElement) (copy as HTMLLinkElement).href = node.href; // absolute: the popup is about:blank
      doc.head.appendChild(copy);
    });

    const root = doc.createElement('div');
    root.className = 've-popout';
    doc.body.replaceChildren(root);

    const closed = () => {
      win.current = null;
      setContainer(null);
      handlers.current.onClosed();
    };
    popup.addEventListener('pagehide', closed);
    popup.addEventListener('keydown', (event) => handlers.current.onKey(event));
    window.addEventListener('beforeunload', () => popup.close());

    win.current = popup;
    setContainer(root);
    return true;
  }, [name, title]);

  return { container, open, close };
};

/* ---------- panel frame ---------- */

type FrameProps = {
  side: Side;
  title: string;
  state: PanelState;
  onState: (state: PanelState) => void;
  onKey: (event: KeyboardEvent) => void;
  /** Extra buttons for the header (right-aligned, before the window controls). */
  actions?: ReactNode;
  children: ReactNode;
};

/**
 * A side panel that can be collapsed to a rail, resized (see Splitter) or moved to its own window.
 * Its children render in the editor, or — through a portal — in the popup window.
 */
export const PanelFrame = ({ side, title, state, onState, onKey, actions, children }: FrameProps) => {
  const [blocked, setBlocked] = useState(false);
  const popout = usePopout({ name: side, title, onClosed: () => onState('docked'), onKey });

  const detach = () => {
    if (popout.open()) {
      setBlocked(false);
      onState('window');
    } else {
      setBlocked(true);
    }
  };
  const redock = () => {
    popout.close();
    onState('docked');
  };

  const content = (
    <div className="ve-panel__inner">
      <div className="ve-panel__head">
        <strong>{title}</strong>
        <span className="ve-spacer" />
        {actions}
        {state === 'window' ? (
          <button type="button" className="ve-icon-btn" title="Bring back into the editor" aria-label={`Dock ${title}`} onClick={redock}>
            ⇲
          </button>
        ) : (
          <>
            <button type="button" className="ve-icon-btn" title="Open in a separate window" aria-label={`Detach ${title}`} onClick={detach}>
              ⧉
            </button>
            <button type="button" className="ve-icon-btn" title="Collapse" aria-label={`Collapse ${title}`} onClick={() => onState('collapsed')}>
              {side === 'left' ? '‹' : '›'}
            </button>
          </>
        )}
      </div>
      {blocked && <p className="ve-note">The browser blocked the new window. Allow pop-ups for this site and try again.</p>}
      {children}
    </div>
  );

  if (state === 'docked') return <aside className={`ve-panel ve-panel--${side}`}>{content}</aside>;

  return (
    <>
      <aside className={`ve-panel ve-panel--${side} ve-rail`}>
        <button
          type="button"
          className="ve-rail__btn"
          title={state === 'window' ? `${title} is in another window — click to bring it back` : `Open ${title}`}
          onClick={state === 'window' ? redock : () => onState('docked')}
        >
          <span aria-hidden>{state === 'window' ? '⇲' : side === 'left' ? '›' : '‹'}</span>
          <span className="ve-rail__label">{title}</span>
        </button>
      </aside>
      {state === 'window' && popout.container && createPortal(content, popout.container)}
    </>
  );
};

/* ---------- splitter ---------- */

type SplitterProps = {
  side: Side;
  width: number;
  onWidth: (width: number) => void;
  onReset: () => void;
  hidden: boolean;
};

/** Drag to resize a side panel; double-click restores its default width. */
export const Splitter = ({ side, width, onWidth, onReset, hidden }: SplitterProps) => {
  const start = (event: ReactPointerEvent<HTMLDivElement>) => {
    event.preventDefault();
    try {
      // Keeps the pointer events coming while the pointer is over the preview iframe.
      event.currentTarget.setPointerCapture(event.pointerId);
    } catch {
      // Not an active pointer (synthetic events): the window listeners below still work.
    }
    const origin = event.clientX;
    const initial = width;

    const move = (next: PointerEvent) => {
      const delta = next.clientX - origin;
      onWidth(initial + (side === 'left' ? delta : -delta));
    };
    const stop = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', stop);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
  };

  return (
    <div
      className={`ve-splitter ve-splitter--${side} ${hidden ? 'is-hidden' : ''}`}
      role="separator"
      aria-orientation="vertical"
      aria-valuemin={MIN_WIDTH}
      aria-valuemax={MAX_WIDTH}
      aria-valuenow={width}
      title="Drag to resize — double-click to reset"
      onPointerDown={start}
      onDoubleClick={onReset}
    />
  );
};
