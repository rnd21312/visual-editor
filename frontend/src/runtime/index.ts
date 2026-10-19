// Public runtime, printed inline by Frontend.php only on pages that need it. Applies content and structural
// edits while the page is parsed (no flash of old content), plays motions, submits builder forms and mounts
// React islands that edits inserted.
import { createApplier } from '../shared/apply';
import { playMotions, type MotionItem } from '../shared/motion';
import type { ContentEdit, RuntimeOp } from '../shared/types';

type Payload = { v: number; edits: ContentEdit[]; ops: RuntimeOp[]; motions: MotionItem[]; reduced: boolean; rest: string };

const read = (): Payload | null => {
  const el = document.getElementById('sve-data');
  if (!el?.textContent) return null;
  try {
    return JSON.parse(el.textContent) as Payload;
  } catch {
    return null;
  }
};

const data = read();
const root = document.documentElement;

if (data && (data.edits.length || data.ops.length)) {
  root.classList.add('sve-pending');
  const reveal = () => root.classList.remove('sve-pending');
  const failsafe = window.setTimeout(reveal, 2500);
  const applier = createApplier(document, () => {
    if (document.readyState !== 'loading') {
      window.clearTimeout(failsafe);
      reveal();
    }
  });
  // While parsing, the applier's own observer (on <html>) applies edits as soon as their elements arrive.
  applier.set(data.edits, data.ops);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      applier.flush();
      (window as Window & { __sveMountIslands?: () => void }).__sveMountIslands?.();
    });
  }
}

// Motions need the page and GSAP (enqueued in the footer).
if (data?.motions?.length) {
  const start = () => playMotions(window as never, data.motions, data.reduced);
  if (document.readyState === 'complete') start();
  else window.addEventListener('load', start, { once: true });
}

// Builder forms: <form data-sve-form="name"> posts to the site and shows a message.
document.addEventListener('submit', async (event) => {
  const form = event.target as HTMLFormElement | null;
  if (!form?.matches?.('form[data-sve-form]')) return;
  event.preventDefault();
  if (form.dataset.sending) return;
  form.dataset.sending = '1';
  const fd = new FormData(form);
  fd.set('_sve_form', form.dataset.sveForm || 'form');
  fd.set('_sve_page', location.href);
  const button = form.querySelector('button[type=submit], button:not([type]), input[type=submit]') as HTMLButtonElement | null;
  button?.setAttribute('disabled', '');
  const say = (text: string, ok: boolean) => {
    let box = form.querySelector('.sve-form-message') as HTMLElement | null;
    if (!box) {
      box = document.createElement('p');
      box.className = 'sve-form-message';
      box.setAttribute('role', 'status');
      form.append(box);
    }
    box.textContent = text;
    box.dataset.state = ok ? 'success' : 'error';
  };
  try {
    const rest = data?.rest || (document.querySelector('link[rel="https://api.w.org/"]') as HTMLLinkElement | null)?.href + 'sve/v1/';
    const res = await fetch(rest + 'form', { method: 'POST', body: fd, credentials: 'same-origin' });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body?.message || 'Error');
    form.reset();
    say(form.dataset.sveSuccess || (document.documentElement.lang.startsWith('fa') ? 'پیام شما ارسال شد. ممنون!' : 'Thanks! Your message was sent.'), true);
    form.dispatchEvent(new CustomEvent('sve:sent', { bubbles: true }));
  } catch (e) {
    say(form.dataset.sveError || (e instanceof Error && e.message !== 'Error' ? e.message : document.documentElement.lang.startsWith('fa') ? 'ارسال نشد. دوباره تلاش کنید.' : 'Sending failed. Please try again.'), false);
  } finally {
    delete form.dataset.sending;
    button?.removeAttribute('disabled');
  }
});
