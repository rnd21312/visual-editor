// Precise edits of builder source by data-sid (TypeScript twin of src/Builder/SourceEditor.php).
// The rest of the source is never re-serialized, so bindings and builder tags stay exactly as written.

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param']);
const NO_ID = new Set(['html', 'head', 'body', 'script', 'style', 'br', 'wbr', 'template', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'g', 'defs', 'stop', 'use', 'option', 'sve-empty', 'sve-slot']);
const ATTRS = `(?:[^>"']|"[^"]*"|'[^']*')*`;

export const newSid = (): string => 's' + Math.random().toString(36).slice(2, 9).padEnd(7, '0');

export const ensureIds = (source: string): string =>
  source.replace(new RegExp(`<([a-zA-Z][a-zA-Z0-9-]*)(?=[\\s>/])(${ATTRS})>`, 'g'), (m, tag: string, rest: string) => {
    if (NO_ID.has(tag.toLowerCase()) || rest.includes('data-sid=')) return m;
    const self = rest.trimEnd().endsWith('/');
    const body = self ? rest.trimEnd().slice(0, -1).trimEnd() : rest;
    return `<${tag}${body} data-sid="${newSid()}"${self ? ' /' : ''}>`;
  });

export const stripIds = (source: string): string => source.replace(/\sdata-sid=["'][^"']*["']/g, '');

export type Located = { start: number; openEnd: number; end: number; closeStart: number; tag: string };

export const locate = (source: string, sid: string): Located | null => {
  const re = new RegExp(`<([a-zA-Z][a-zA-Z0-9-]*)${ATTRS}?\\sdata-sid=["']${sid.replace(/[^\w-]/g, '')}["']${ATTRS}>`);
  const m = re.exec(source);
  if (!m) return null;
  const start = m.index;
  const openEnd = start + m[0].length;
  const tag = m[1].toLowerCase();
  if (VOID.has(tag) || m[0].slice(0, -1).trimEnd().endsWith('/')) return { start, openEnd, end: openEnd, closeStart: openEnd, tag };
  const tagRe = new RegExp(`<(/?)${tag}(?=[\\s>/])${ATTRS}>`, 'gi');
  tagRe.lastIndex = openEnd;
  let depth = 1;
  let t: RegExpExecArray | null;
  while (depth > 0 && (t = tagRe.exec(source))) {
    if (t[1] === '/') {
      depth--;
      if (depth === 0) return { start, openEnd, end: t.index + t[0].length, closeStart: t.index, tag };
    } else if (!t[0].slice(0, -1).trimEnd().endsWith('/')) depth++;
  }
  return { start, openEnd, end: source.length, closeStart: source.length, tag };
};

const escapeHtml = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const escapeAttr = (s: string) => escapeHtml(s).replace(/"/g, '&quot;');

export const setAttr = (open: string, name: string, value: string | null): string => {
  if (!/^[a-zA-Z_:@][-a-zA-Z0-9_:.]*$/.test(name) || name === 'data-sid') return open;
  const re = new RegExp(`\\s${name.replace(/[.:]/g, '\\$&')}(?:\\s*=\\s*(?:"[^"]*"|'[^']*'|[^\\s>]+))?(?=[\\s>/])`, 'g');
  let out = open.replace(re, '');
  if (value === null) return out;
  out = out.replace(/(\s*\/?>)$/, ` ${name}="${escapeAttr(value)}"$1`);
  return out;
};

export const getAttrs = (open: string): Record<string, string> => {
  const out: Record<string, string> = {};
  const body = open.replace(/^<[a-zA-Z][\w-]*/, '').replace(/\/?>$/, '');
  const re = /([a-zA-Z_:@][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>/=`]+)))?/g;
  let m: RegExpExecArray | null;
  const txt = document.createElement('textarea');
  while ((m = re.exec(body))) {
    txt.innerHTML = m[2] ?? m[3] ?? m[4] ?? '';
    out[m[1].toLowerCase()] = txt.value;
  }
  return out;
};

export type SourceAction =
  | 'replace'
  | 'remove'
  | 'insert_before'
  | 'insert_after'
  | 'prepend'
  | 'append'
  | 'set_inner'
  | 'set_text'
  | 'set_attrs'
  | 'wrap'
  | 'unwrap'
  | 'duplicate'
  | 'move_before'
  | 'move_after'
  | 'move_into';

export type SourceArgs = { html?: string; text?: string; attrs?: Record<string, string | null>; wrapper?: string; target?: string };

export const applySource = (source: string, sid: string, action: SourceAction, args: SourceArgs = {}): { source: string; newSid?: string } | null => {
  const at = locate(source, sid);
  if (!at) return null;
  const html = ensureIds(args.html ?? '');
  const firstSid = /data-sid="([^"]+)"/.exec(html)?.[1];
  const s = source;
  switch (action) {
    case 'replace':
      return { source: s.slice(0, at.start) + html + s.slice(at.end), newSid: firstSid };
    case 'remove':
      return { source: s.slice(0, at.start) + s.slice(at.end) };
    case 'insert_before':
      return { source: s.slice(0, at.start) + html + s.slice(at.start), newSid: firstSid };
    case 'insert_after':
      return { source: s.slice(0, at.end) + html + s.slice(at.end), newSid: firstSid };
    case 'prepend':
      return { source: s.slice(0, at.openEnd) + html + s.slice(at.openEnd), newSid: firstSid };
    case 'append':
      return { source: s.slice(0, at.closeStart) + html + s.slice(at.closeStart), newSid: firstSid };
    case 'set_inner':
      return { source: s.slice(0, at.openEnd) + html + s.slice(at.closeStart) };
    case 'set_text':
      return { source: s.slice(0, at.openEnd) + escapeHtml(args.text ?? '') + s.slice(at.closeStart) };
    case 'set_attrs': {
      let open = s.slice(at.start, at.openEnd);
      for (const [name, value] of Object.entries(args.attrs ?? {})) open = setAttr(open, name, value);
      return { source: s.slice(0, at.start) + open + s.slice(at.openEnd) };
    }
    case 'wrap': {
      const w = /^<([a-zA-Z][a-zA-Z0-9-]*)[^>]*>$/.exec((args.wrapper ?? '<div>').trim());
      if (!w) return null;
      const open = ensureIds(w[0]);
      return { source: s.slice(0, at.start) + open + s.slice(at.start, at.end) + `</${w[1]}>` + s.slice(at.end), newSid: /data-sid="([^"]+)"/.exec(open)?.[1] };
    }
    case 'unwrap':
      return { source: s.slice(0, at.start) + s.slice(at.openEnd, at.closeStart) + s.slice(at.end) };
    case 'duplicate': {
      const copy = ensureIds(stripIds(s.slice(at.start, at.end)));
      return { source: s.slice(0, at.end) + copy + s.slice(at.end), newSid: /data-sid="([^"]+)"/.exec(copy)?.[1] };
    }
    case 'move_before':
    case 'move_after':
    case 'move_into': {
      const piece = s.slice(at.start, at.end);
      const rest = s.slice(0, at.start) + s.slice(at.end);
      const to = locate(rest, args.target ?? '');
      if (!to) return null;
      const pos = action === 'move_before' ? to.start : action === 'move_after' ? to.end : to.closeStart;
      return { source: rest.slice(0, pos) + piece + rest.slice(pos), newSid: sid };
    }
  }
  return null;
};

/** Inner source of an element (to edit it as code). */
export const innerOf = (source: string, sid: string): string | null => {
  const at = locate(source, sid);
  return at ? source.slice(at.openEnd, at.closeStart) : null;
};

export const outerOf = (source: string, sid: string): string | null => {
  const at = locate(source, sid);
  return at ? source.slice(at.start, at.end) : null;
};

export const openTagOf = (source: string, sid: string): string | null => {
  const at = locate(source, sid);
  return at ? source.slice(at.start, at.openEnd) : null;
};

/** Whether the inner source is plain text (safe to edit inline). */
export const isPlainText = (inner: string): boolean => !/[<{]/.test(inner);
