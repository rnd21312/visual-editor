// Element motions played with GSAP (+ ScrollTrigger). Shared by the public runtime and the editor preview.
import type { Motion } from './types';

type Gsap = {
  set: (t: unknown, v: Record<string, unknown>) => unknown;
  from: (t: unknown, v: Record<string, unknown>) => Tween;
  to: (t: unknown, v: Record<string, unknown>) => Tween;
  fromTo: (t: unknown, a: Record<string, unknown>, b: Record<string, unknown>) => Tween;
  registerPlugin?: (...p: unknown[]) => void;
};
type Tween = { kill: () => void; play: (from?: number) => Tween; reverse: () => Tween; restart: () => Tween; pause: () => Tween; progress: (p: number) => Tween };
type W = Window & { gsap?: Gsap; ScrollTrigger?: { refresh: () => void; getAll?: () => { kill: () => void }[] } };

export type MotionItem = { sel: string; m: Motion };

/** From-state of each preset (animated to the element's natural state). */
const presets = (m: Motion): Record<string, unknown> | null => {
  const d = m.distance || 40;
  switch (m.preset) {
    case 'fade':
      return { opacity: 0 };
    case 'fade-up':
    case 'slide-up':
      return { opacity: m.preset === 'fade-up' ? 0 : 1, y: d };
    case 'fade-down':
    case 'slide-down':
      return { opacity: m.preset === 'fade-down' ? 0 : 1, y: -d };
    case 'fade-left':
    case 'slide-left':
      return { opacity: m.preset === 'fade-left' ? 0 : 1, x: d };
    case 'fade-right':
    case 'slide-right':
      return { opacity: m.preset === 'fade-right' ? 0 : 1, x: -d };
    case 'zoom-in':
      return { opacity: 0, scale: 0.85 };
    case 'zoom-out':
      return { opacity: 0, scale: 1.15 };
    case 'flip-up':
      return { opacity: 0, rotationX: 70, transformPerspective: 800, transformOrigin: '50% 100%' };
    case 'rotate-in':
      return { opacity: 0, rotation: -8, scale: 0.95 };
    case 'blur-in':
      return { opacity: 0, filter: 'blur(12px)' };
    case 'stagger-children':
    case 'reveal-text':
      return { opacity: 0, y: Math.min(d, 24) };
    case 'custom':
      return m.from ?? {};
    default:
      return null;
  }
};

const splitWords = (el: Element) => {
  if ((el as HTMLElement).dataset.sveSplit) return Array.from(el.querySelectorAll('.sve-word'));
  (el as HTMLElement).dataset.sveSplit = '1';
  const walk = (node: Node) => {
    for (const child of Array.from(node.childNodes)) {
      if (child.nodeType === 3) {
        const frag = el.ownerDocument.createDocumentFragment();
        (child.nodeValue ?? '').split(/(\s+)/).forEach((part) => {
          if (/^\s+$/.test(part) || part === '') frag.append(part);
          else {
            const s = el.ownerDocument.createElement('span');
            s.className = 'sve-word';
            s.style.display = 'inline-block';
            s.textContent = part;
            frag.append(s);
          }
        });
        child.replaceWith(frag);
      } else if (child.nodeType === 1) walk(child);
    }
  };
  walk(el);
  return Array.from(el.querySelectorAll('.sve-word'));
};

/**
 * Starts the motions; returns a cleanup that kills them (the editor replays on every change).
 */
export const playMotions = (win: W, items: MotionItem[], reducedMotion = true): (() => void) => {
  const gsap = win.gsap;
  const killers: (() => void)[] = [];
  if (!gsap || items.length === 0) return () => undefined;
  if (reducedMotion && win.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return () => undefined;
  if (win.ScrollTrigger && gsap.registerPlugin) gsap.registerPlugin(win.ScrollTrigger);
  const doc = win.document;

  for (const { sel, m } of items) {
    let els: Element[] = [];
    try {
      els = Array.from(doc.querySelectorAll(sel));
    } catch {
      continue;
    }
    for (const el of els) {
      const base = { duration: m.duration, delay: m.delay, ease: m.ease, repeat: m.repeat, yoyo: m.yoyo };
      const scroll = win.ScrollTrigger ? { trigger: el, start: m.start || 'top 85%', toggleActions: m.once ? 'play none none none' : 'play none none reverse' } : undefined;

      if (m.preset === 'parallax' || m.trigger === 'scrub') {
        if (!win.ScrollTrigger) continue;
        const vars = m.preset === 'parallax' ? { y: -(m.distance || 80) } : { opacity: 1, x: 0, y: 0, scale: 1, rotation: 0, filter: 'blur(0px)', ...(m.to ?? {}) };
        const from = m.preset === 'parallax' ? { y: m.distance || 80 } : (presets(m) ?? {});
        const t = gsap.fromTo(el, from, { ...vars, ease: 'none', scrollTrigger: { trigger: el, start: 'top bottom', end: 'bottom top', scrub: true } });
        killers.push(() => t.kill());
        continue;
      }
      if (m.preset === 'float' || m.preset === 'pulse' || m.preset === 'spin') {
        const loop = m.preset === 'float' ? { y: -(m.distance || 12), yoyo: true } : m.preset === 'pulse' ? { scale: 1.06, yoyo: true } : { rotation: 360, ease: 'none' };
        const t = gsap.to(el, { duration: m.duration || 2, ease: 'sine.inOut', repeat: -1, ...loop });
        killers.push(() => t.kill());
        continue;
      }

      const from = presets(m);
      if (!from) continue;
      const targets = m.preset === 'stagger-children' ? Array.from(el.children) : m.preset === 'reveal-text' ? splitWords(el) : el;
      const stagger = m.stagger || (m.preset === 'stagger-children' ? 0.12 : m.preset === 'reveal-text' ? 0.05 : 0);

      if (m.trigger === 'hover' || m.trigger === 'click') {
        const t = gsap.from(targets, { ...from, ...base, stagger, paused: true });
        const type = m.trigger === 'hover' ? 'mouseenter' : 'click';
        const play = () => t.restart();
        el.addEventListener(type, play);
        if (m.trigger === 'hover') el.addEventListener('mouseleave', () => t.reverse());
        t.progress(1);
        killers.push(() => {
          el.removeEventListener(type, play);
          t.kill();
        });
        continue;
      }
      const vars: Record<string, unknown> = { ...from, ...base, stagger, immediateRender: true };
      if (m.preset === 'custom' && m.to) {
        const t = gsap.fromTo(targets, from, { ...m.to, ...base, stagger, ...(m.trigger === 'scroll' && scroll ? { scrollTrigger: scroll } : {}) });
        killers.push(() => t.kill());
        continue;
      }
      if (m.trigger === 'scroll' && scroll) vars.scrollTrigger = scroll;
      const t = gsap.from(targets, vars);
      killers.push(() => t.kill());
    }
  }
  win.ScrollTrigger?.refresh();
  return () => killers.forEach((k) => k());
};
