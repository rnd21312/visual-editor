// Live collaboration: polls the site for AI requests while the editor is open and answers what only a browser
// knows (computed styles, boxes, screenshots, JSX compilation), shows what the AI points at, and refreshes when
// an AI changes the site.
import { selectorFor } from '../shared/selector';
import { compileJsx } from './jsx';
import { findAgain } from './frame';
import { select } from './actions';
import { useStore, type Activity } from './store';
import { t } from './i18n';

type Job = { id: string; type: string; args: Record<string, unknown> };

const WIDTH: Record<string, number> = { desktop: 1366, tablet: 820, mobile: 390 };
const STYLE_PROPS = ['display', 'position', 'width', 'height', 'margin', 'padding', 'color', 'background-color', 'background-image', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align', 'text-transform', 'border', 'border-radius', 'box-shadow', 'opacity', 'z-index', 'gap', 'flex-direction', 'justify-content', 'align-items', 'grid-template-columns', 'transform', 'transition', 'overflow', 'cursor', 'visibility'];

let since = 0;
let timer = 0;
let busy = false;

/** Loads a page in an invisible iframe (as visitors see it, without the editor) and waits for it. */
const offscreen = (url: string, device: string): Promise<{ frame: HTMLIFrameElement; doc: Document }> =>
  new Promise((resolve, reject) => {
    const frame = document.createElement('iframe');
    const w = WIDTH[device] ?? 1366;
    frame.style.cssText = `position:fixed;left:-20000px;top:0;width:${w}px;height:${device === 'mobile' ? 844 : device === 'tablet' ? 1180 : 900}px;border:0;visibility:hidden`;
    frame.setAttribute('aria-hidden', 'true');
    const done = window.setTimeout(() => reject(new Error('The page took too long to load.')), 20000);
    frame.onload = () => {
      window.clearTimeout(done);
      const doc = frame.contentDocument;
      if (!doc) return reject(new Error('The page could not be read.'));
      // Let fonts, images and the runtime settle.
      window.setTimeout(() => resolve({ frame, doc }), 1200);
    };
    frame.src = url + (url.includes('?') ? '&' : '?') + 'sve_shot=1';
    document.body.append(frame);
  });

type Snapper = { toJpeg: (node: HTMLElement, opts: Record<string, unknown>) => Promise<string> };

/** html-to-image must run in the page's own window (its styles, fonts and canvas). */
const snapperFor = async (doc: Document): Promise<Snapper> => {
  const win = doc.defaultView as (Window & { eval: (code: string) => unknown; __sveSnap?: Promise<Snapper> }) | null;
  if (!win) throw new Error('No window.');
  win.__sveSnap ??= win.eval('import("https://esm.sh/html-to-image@1.11.11")') as Promise<Snapper>;
  return win.__sveSnap;
};

/** Finishes running GSAP animations so nothing is captured half-transparent. */
const settle = (doc: Document) => {
  const win = doc.defaultView as (Window & { gsap?: { globalTimeline: { getChildren: () => { progress: (p: number) => void }[] } } }) | null;
  try {
    win?.gsap?.globalTimeline.getChildren().forEach((tw) => tw.progress(1));
  } catch {
    /* no GSAP */
  }
};

const snap = async (node: HTMLElement, width: number, height?: number, exact = false): Promise<string> => {
  const doc = node.ownerDocument;
  settle(doc);
  const lib = await snapperFor(doc);
  const pixelRatio = Math.min(1, 1200 / Math.max(width, 1));
  // The background option replaces the node's own background: only fill when it is transparent.
  const bg = doc.defaultView?.getComputedStyle(node).backgroundColor ?? '';
  const transparent = bg === '' || bg === 'transparent' || /rgba\(.*,\s*0\)$/.test(bg);
  // Elements size themselves (explicit sizes shift the clone); pages are cropped to a height.
  return lib.toJpeg(node, { quality: 0.85, pixelRatio, cacheBust: false, ...(exact ? { width, height } : {}), ...(transparent ? { backgroundColor: '#ffffff' } : {}) });
};

const inspect = async (args: Record<string, unknown>) => {
  const { frame, doc } = await offscreen(String(args.url), String(args.device ?? 'desktop'));
  try {
    let el: Element | null = null;
    try {
      el = doc.querySelector(String(args.selector));
    } catch {
      throw new Error('Invalid selector.');
    }
    if (!el) return { found: false, note: 'No element matches in the live page (it may be added by JavaScript later, or hidden on this device).' };
    const win = doc.defaultView!;
    el.scrollIntoView({ block: 'center' });
    const cs = win.getComputedStyle(el);
    const r = el.getBoundingClientRect();
    const out: Record<string, unknown> = {
      found: true,
      device: args.device ?? 'desktop',
      box: { x: Math.round(r.left), y: Math.round(r.top + win.scrollY), width: Math.round(r.width), height: Math.round(r.height) },
      visible: r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none',
      computed: Object.fromEntries(STYLE_PROPS.map((p) => [p, cs.getPropertyValue(p)]).filter(([, v]) => v && v !== 'none' && v !== 'normal' && v !== 'auto' && v !== '0px')),
      text: (el.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 300),
    };
    if (args.screenshot !== false && r.width > 0 && r.height > 0) {
      // Element with a little context around it.
      const target = (r.width < 200 && el.parentElement ? el.parentElement : el) as HTMLElement;
      const tr = target.getBoundingClientRect();
      out.image = await snap(target, Math.min(tr.width, 1600), Math.min(tr.height, 1600));
    }
    return out;
  } finally {
    frame.remove();
  }
};

const screenshot = async (args: Record<string, unknown>) => {
  const device = String(args.device ?? 'desktop');
  const { frame, doc } = await offscreen(String(args.url), device);
  try {
    const w = WIDTH[device] ?? 1366;
    if (args.selector) {
      const el = doc.querySelector(String(args.selector)) as HTMLElement | null;
      if (!el) return { error: 'No element matches ' + String(args.selector) };
      el.scrollIntoView({ block: 'start' });
      const r = el.getBoundingClientRect();
      return { device, image: await snap(el, Math.min(r.width, 2000), Math.min(r.height, 3000)) };
    }
    const height = args.full ? Math.min(doc.documentElement.scrollHeight, 6000) : frame.clientHeight;
    return { device, width: w, height, image: await snap(doc.documentElement, w, height, true) };
  } finally {
    frame.remove();
  }
};

const find = async (args: Record<string, unknown>) => {
  const { frame, doc } = await offscreen(String(args.url), String(args.device ?? 'desktop'));
  try {
    const text = String(args.text ?? '').toLowerCase();
    const out = Array.from(doc.querySelectorAll('a,button,h1,h2,h3,h4,p,span,li,img,input,label'))
      .filter((el) => !text || (el.textContent ?? '').toLowerCase().includes(text) || (el.getAttribute('alt') ?? '').toLowerCase().includes(text))
      .slice(0, 15)
      .map((el) => ({ selector: selectorFor(el), tag: el.localName, text: (el.textContent ?? '').trim().slice(0, 120) }));
    return { matches: out };
  } finally {
    frame.remove();
  }
};

const handle = async (job: Job): Promise<Record<string, unknown>> => {
  const s = useStore.getState();
  switch (job.type) {
    case 'inspect':
      return inspect(job.args);
    case 'screenshot':
      return screenshot(job.args);
    case 'find':
      return find(job.args);
    case 'compile':
      try {
        return { code: (await compileJsx(String(job.args.jsx ?? ''), s.cfg.react)).code };
      } catch (e) {
        return { error: e instanceof Error ? e.message : String(e) };
      }
    case 'navigate':
      if (job.args.url) s.navigate(String(job.args.url));
      return { ok: true };
    case 'highlight': {
      const url = String(job.args.url ?? '');
      const point = () => {
        const el = job.args.selector ? findAgain(String(job.args.selector)) : null;
        if (el) {
          select(el);
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        useStore.setState({ liveNote: { selector: String(job.args.selector ?? ''), message: String(job.args.message ?? '') } });
      };
      if (url && url.replace(/\/$/, '') !== s.url.replace(/\/$/, '')) {
        s.navigate(url);
        const wait = window.setInterval(() => {
          if (!useStore.getState().loading) {
            window.clearInterval(wait);
            point();
          }
        }, 300);
      } else point();
      if (job.args.message) s.toast('🤖 ' + String(job.args.message), 'info');
      return { ok: true };
    }
    case 'reload':
      if (!s.dirty()) void s.refreshDoc();
      return { ok: true };
    case 'message':
      s.toast('🤖 ' + String(job.args.text ?? ''), 'info');
      return { ok: true };
  }
  return { error: 'Unknown job ' + job.type };
};

const runJobs = (jobs: Job[]) => {
  const s = useStore.getState();
  for (const job of jobs) {
    handle(job)
      .then((result) => s.api.post(`live/jobs/${job.id}`, result))
      .catch((e) => s.api.post(`live/jobs/${job.id}`, { error: e instanceof Error ? e.message : String(e) }));
  }
};

const poll = async () => {
  const s = useStore.getState();
  if (busy) return;
  busy = true;
  try {
    const r = await s.api.get<{ jobs: Job[]; activity: Activity[]; rev: number; draft: number; now: number }>('live', { url: s.url, since });
    if (r.activity.length) {
      since = Math.max(since, ...r.activity.map((a) => a.time));
      useStore.setState({ activity: [...useStore.getState().activity, ...r.activity].slice(-200) });
      const ai = r.activity.filter((a) => a.actor.startsWith('ai:'));
      if (ai.length && since > 0) ai.slice(-2).forEach((a) => s.toast('🤖 ' + a.summary, 'info'));
    }
    useStore.setState({ draft: r.draft });
    // Someone else published (an AI with a live key, another person): follow along when nothing is unsaved.
    if (r.rev !== useStore.getState().base.rev && r.rev !== useStore.getState().remoteRev) {
      useStore.setState({ remoteRev: r.rev });
      if (!s.dirty()) void s.refreshDoc();
      else s.toast(t('The site was changed by someone else. Your next publish merges both.'), 'info');
    }
    runJobs(r.jobs);
  } catch {
    /* offline for a moment */
  } finally {
    busy = false;
  }
};

export const startLive = (): void => {
  // Only show activity from now on (older items are listed once).
  void useStore
    .getState()
    .api.get<{ activity: Activity[]; jobs: Job[] }>('live', { url: useStore.getState().url, since: 0 })
    .then((r) => {
      runJobs(r.jobs);
      useStore.setState({ activity: r.activity.slice(-60) });
      since = r.activity.length ? Math.max(...r.activity.map((a) => a.time)) : Date.now() / 1000;
    })
    .catch(() => undefined);
  // Background tabs throttle timers heavily (the AI app is usually in front): a worker keeps the beat.
  window.clearInterval(timer);
  try {
    const blob = new Blob(['setInterval(function(){postMessage(1)},2000)'], { type: 'text/javascript' });
    const worker = new Worker(URL.createObjectURL(blob));
    worker.onmessage = () => void poll();
  } catch {
    timer = window.setInterval(() => void poll(), 2000);
  }
};
