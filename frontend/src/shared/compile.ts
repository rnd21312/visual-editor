// TypeScript twin of src/Compiler.php: an edit → the CSS the site prints (used for the live preview).
import type { Edit } from './types';

const STATES: Record<string, string> = {
  hover: ':hover',
  focus: ':focus',
  active: ':active',
  'focus-visible': ':focus-visible',
  'focus-within': ':focus-within',
  'first-child': ':first-child',
  'last-child': ':last-child',
  before: '::before',
  after: '::after',
  placeholder: '::placeholder',
};

export type Breakpoints = { tablet: number; mobile: number };

export const media = (bp: Breakpoints): Record<'base' | 'tablet' | 'mobile', string> => ({
  base: '',
  tablet: `(max-width: ${bp.tablet}px)`,
  mobile: `(max-width: ${bp.mobile}px)`,
});

/** Splits a selector list at top-level commas. */
export const splitSelector = (sel: string): string[] => {
  const parts: string[] = [];
  let depth = 0;
  let quote = '';
  let buf = '';
  for (let i = 0; i < sel.length; i++) {
    const c = sel[i];
    if (quote) {
      buf += c;
      if (c === '\\' && i + 1 < sel.length) buf += sel[++i];
      else if (c === quote) quote = '';
      continue;
    }
    if (c === '"' || c === "'") quote = c;
    else if (c === '(' || c === '[') depth++;
    else if (c === ')' || c === ']') depth = Math.max(0, depth - 1);
    else if (c === ',' && depth === 0) {
      parts.push(buf.trim());
      buf = '';
      continue;
    }
    buf += c;
  }
  parts.push(buf.trim());
  return parts.filter(Boolean);
};

const declarations = (styles: Record<string, string> | undefined): string =>
  Object.entries(styles ?? {})
    .map(([p, v]) => [p.trim(), String(v).replace(/!important/gi, '').trim()] as const)
    .filter(([p, v]) => p !== '' && v !== '')
    .map(([p, v]) => `${p}:${v} !important`)
    .join(';');

const wrap = (m: string, rule: string) => (m ? `@media ${m}{${rule}}` : rule);

export const customCss = (css: string, sel: string): string => {
  if (!css.includes('{')) return `${sel}{${css}}`;
  const self = splitSelector(sel).length > 1 ? `:is(${sel})` : sel; // a list stays one unit in "selector > i"
  return css.replace(/(?<![\w\-.#])selector(?![\w-])/g, () => self).replace(/(^|[},]\s*)&/g, (_m, lead: string) => lead + self);
};

export const compileEdit = (edit: Edit, bp: Breakpoints = { tablet: 1023, mobile: 767 }): string => {
  const sel = edit.sel;
  const parts = splitSelector(sel);
  const m = media(bp);
  const out: string[] = [];
  (['base', 'tablet', 'mobile'] as const).forEach((d) => {
    const body = declarations(edit.styles?.[d]);
    if (body) out.push(wrap(m[d], `${sel}{${body}}`));
  });
  Object.entries(STATES).forEach(([state, suffix]) => {
    (['base', 'tablet', 'mobile'] as const).forEach((d) => {
      let styles = (edit.states as Record<string, Record<string, Record<string, string>>> | undefined)?.[state]?.[d];
      if (styles && (state === 'before' || state === 'after') && !('content' in styles)) styles = { content: '""', ...styles };
      const body = declarations(styles);
      if (body) out.push(wrap(m[d], `${parts.map((p) => p + suffix).join(',')}{${body}}`));
    });
  });
  const hide = {
    desktop: `(min-width: ${bp.tablet + 1}px)`,
    tablet: `(min-width: ${bp.mobile + 1}px) and (max-width: ${bp.tablet}px)`,
    mobile: `(max-width: ${bp.mobile}px)`,
  };
  (Object.keys(hide) as (keyof typeof hide)[]).forEach((k) => {
    if (edit.hide?.[k]) out.push(`@media ${hide[k]}{${sel}{display:none !important}}`);
  });
  if (edit.css?.trim()) out.push(customCss(edit.css.trim(), sel));
  return out.join('\n');
};
