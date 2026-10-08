import { useEffect, useImperativeHandle, useRef, type Ref } from 'react';
import { createApplier } from '@/lib/visualApply';
import type { Device } from '@/lib/visualCss';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { Overlay } from './Overlay';
import { pickElement } from './selector';
import type { Frame, Mode } from './types';

export type CanvasHandle = {
  /** Start typing on an element (same as double-clicking it). */
  inline: (el: HTMLElement) => void;
  navigate: (url: string) => void;
  reload: () => void;
  scrollTo: (el: Element) => void;
  iframe: () => HTMLIFrameElement | null;
};

export type InlineChange = { text?: string; html?: string };

type Props = {
  ref?: Ref<CanvasHandle>;
  initialUrl: string;
  previewArg: string;
  device: Device;
  mode: Mode;
  selected: HTMLElement | null;
  hovered: HTMLElement | null;
  multi: HTMLElement[];
  onToggleMulti: (el: HTMLElement) => void;
  onContext: (el: HTMLElement, x: number, y: number) => void;
  onFrame: (frame: Frame | null) => void;
  onNavigate: (url: string) => void;
  onSelect: (el: HTMLElement | null) => void;
  onHover: (el: HTMLElement | null) => void;
  onKey: (event: KeyboardEvent) => void;
  onInline: (el: HTMLElement, change: InlineChange) => void;
  onHandleDown: (event: ReactPointerEvent) => void;
};

const WIDTHS: Record<Device, string> = { base: '100%', tablet: '820px', mobile: '390px' };

/** Makes the preview show everything at once and look like a canvas rather than a live site. */
const BASE_CSS = `
[data-reveal],.stz-intro{opacity:1!important;transform:none!important;animation:none!important;filter:none!important}
html{scroll-behavior:auto!important}
#wpadminbar{display:none!important}
html.ve-inspect,html.ve-inspect *{cursor:default!important}
[contenteditable="true"]{outline:2px solid #4f8cff!important;outline-offset:2px;cursor:text!important}
`;

const BLOCKED = ['pointerdown', 'pointerup', 'mousedown', 'mouseup', 'touchstart', 'touchend', 'submit', 'auxclick'];

const withParam = (raw: string, arg: string): string => {
  const url = new URL(raw, window.location.href);
  url.searchParams.set(arg, '1');
  return url.toString();
};

const cleanUrl = (raw: string, arg: string): string => {
  const url = new URL(raw);
  url.searchParams.delete(arg);
  return url.toString();
};

export const Canvas = ({
  ref,
  initialUrl,
  previewArg,
  device,
  mode,
  selected,
  hovered,
  multi,
  onToggleMulti,
  onContext,
  onFrame,
  onNavigate,
  onSelect,
  onHover,
  onKey,
  onInline,
  onHandleDown,
}: Props) => {
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const modeRef = useRef(mode);
  const editing = useRef<HTMLElement | null>(null);
  const callbacks = useRef({ onFrame, onNavigate, onSelect, onHover, onKey, onInline, onToggleMulti, onContext });
  const inlineRef = useRef<(el: HTMLElement) => void>(() => undefined);
  const current = useRef<Frame | null>(null);

  useEffect(() => {
    modeRef.current = mode;
    callbacks.current = { onFrame, onNavigate, onSelect, onHover, onKey, onInline, onToggleMulti, onContext };
    current.current?.doc.documentElement.classList.toggle('ve-inspect', mode === 'inspect');
  });

  useImperativeHandle(ref, () => ({
    inline: (el) => inlineRef.current(el),
    navigate: (url) => iframeRef.current?.contentWindow?.location.assign(withParam(url, previewArg)),
    reload: () => iframeRef.current?.contentWindow?.location.reload(),
    scrollTo: (el) => el.scrollIntoView({ block: 'center', behavior: 'instant' as ScrollBehavior }),
    iframe: () => iframeRef.current,
  }));

  const startInline = (el: HTMLElement, win: Window & typeof globalThis) => {
    const before = el.innerHTML;
    const hadChildren = el.children.length > 0;
    editing.current = el;
    el.contentEditable = 'true';
    el.focus();
    const range = el.ownerDocument.createRange();
    range.selectNodeContents(el);
    win.getSelection()?.removeAllRanges();
    win.getSelection()?.addRange(range);

    let done = false;
    const finish = (save: boolean) => {
      if (done) return;
      done = true;
      el.removeEventListener('blur', onBlur);
      el.removeEventListener('keydown', onKeyDown);
      el.removeAttribute('contenteditable');
      editing.current = null;
      if (save && el.innerHTML !== before) {
        callbacks.current.onInline(el, hadChildren ? { html: el.innerHTML } : { text: el.textContent ?? '' });
      } else {
        el.innerHTML = before;
      }
    };
    const onBlur = () => finish(true);
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        finish(false);
      } else if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        finish(true);
        el.blur();
      }
    };
    el.addEventListener('blur', onBlur);
    el.addEventListener('keydown', onKeyDown);
  };

  const onLoad = () => {
    const win = iframeRef.current?.contentWindow as (Window & typeof globalThis) | null | undefined;
    let doc: Document | undefined;
    let href = '';
    try {
      doc = win?.document;
      href = win?.location.href ?? '';
    } catch {
      return; // left the site (cross-origin)
    }
    if (!win || !doc?.body || !href || new URL(href).origin !== window.location.origin) return;

    // A link inside the page led here without the preview flag: reload with it so the saved edits stay out.
    if (!new URL(href).searchParams.has(previewArg)) {
      win.location.replace(withParam(href, previewArg));
      return;
    }

    current.current?.applier.disconnect();

    const base = doc.createElement('style');
    base.textContent = BASE_CSS;
    base.setAttribute('data-ve-editor', '');
    const styleEl = doc.createElement('style');
    styleEl.setAttribute('data-ve-editor', '');
    styleEl.id = 'sve-live';
    doc.head.append(base, styleEl);
    doc.documentElement.classList.toggle('ve-inspect', modeRef.current === 'inspect');

    const block = (event: Event) => {
      if (modeRef.current !== 'inspect' || editing.current) return;
      event.preventDefault();
      event.stopPropagation();
    };
    BLOCKED.forEach((type) => doc.addEventListener(type, block, true));

    doc.addEventListener(
      'click',
      (event) => {
        const anchor = (event.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null;

        if (modeRef.current === 'browse') {
          const local = anchor && anchor.origin === window.location.origin && !anchor.target && !anchor.hash;
          if (local && !event.defaultPrevented && !event.metaKey && !event.ctrlKey) {
            event.preventDefault();
            event.stopPropagation();
            win.location.assign(withParam(anchor.href, previewArg));
          }
          return;
        }
        if (editing.current) return;

        event.preventDefault();
        event.stopPropagation();
        const picked = pickElement(event.target);
        if (picked && (event.ctrlKey || event.metaKey)) callbacks.current.onToggleMulti(picked);
        else callbacks.current.onSelect(picked);
      },
      true,
    );

    doc.addEventListener(
      'contextmenu',
      (event) => {
        if (modeRef.current !== 'inspect' || editing.current) return;
        event.preventDefault();
        event.stopPropagation();
        const picked = pickElement(event.target);
        const offset = iframeRef.current?.getBoundingClientRect();
        if (picked && offset) callbacks.current.onContext(picked, offset.left + event.clientX, offset.top + event.clientY);
      },
      true,
    );

    doc.addEventListener(
      'dblclick',
      (event) => {
        if (modeRef.current !== 'inspect' || editing.current) return;
        const el = pickElement(event.target);
        if (el) startInline(el, win);
      },
      true,
    );

    doc.addEventListener('mousemove', (event) => {
      if (modeRef.current === 'inspect' && !editing.current) callbacks.current.onHover(pickElement(event.target));
    });
    doc.documentElement.addEventListener('mouseleave', () => callbacks.current.onHover(null));
    doc.addEventListener('keydown', (event) => callbacks.current.onKey(event));

    inlineRef.current = (el) => startInline(el, win);
    const meta = doc.querySelector('meta[name="sve-template"]');
    const template = {
      key: meta?.getAttribute('content') ?? '',
      label: meta?.getAttribute('data-label') ?? '',
      templated: meta?.getAttribute('data-templated') === '1',
      count: Number(meta?.getAttribute('data-count') ?? 0),
    };
    const frame: Frame = { win, doc, styleEl, template, applier: createApplier(doc) };
    current.current = frame;
    callbacks.current.onFrame(frame);
    callbacks.current.onNavigate(cleanUrl(href, previewArg));
  };

  return (
    <div className="ve-stage">
      <div className="ve-frame" style={{ width: WIDTHS[device] }} data-device={device}>
        <iframe
          ref={iframeRef}
          title="Site preview"
          src={withParam(initialUrl, previewArg)}
          onLoad={onLoad}
        />
        <Overlay hovered={hovered} selected={selected} multi={multi} onHandleDown={onHandleDown} />
      </div>
    </div>
  );
};
