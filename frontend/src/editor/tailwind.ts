// Tailwind CSS v4 compiled in the editor from the classes the site actually uses. The public site only gets
// the resulting plain CSS (doc.tailwind.css) — no compiler, no CDN.
import { compile } from 'tailwindcss';
import themeCss from 'tailwindcss/theme.css?raw';
import utilitiesCss from 'tailwindcss/utilities.css?raw';
import preflightCss from 'tailwindcss/preflight.css?raw';
import { frameDoc } from './frame';
import type { useStore } from './store';

type State = ReturnType<typeof useStore.getState>;

const CLASS_RE = /\bclass(?:Name)?\s*=\s*(?:"([^"]*)"|'([^']*)'|\{?`([^`]*)`\}?)/g;

const collect = (text: string, out: Set<string>) => {
  for (const m of text.matchAll(CLASS_RE)) {
    (m[1] ?? m[2] ?? m[3] ?? '').split(/\s+/).forEach((c) => c && !c.includes('{{') && out.add(c));
  }
};

export const compileTailwind = async (s: State, preflight: boolean): Promise<string> => {
  const candidates = new Set<string>();
  const { classes } = await s.api.get<{ classes: string[] }>('classes');
  classes.forEach((c) => candidates.add(c));
  s.doc.components.forEach((c) => {
    collect(c.html, candidates);
    if (c.jsx) collect(c.jsx, candidates);
  });
  s.doc.ops.forEach((o) => o.html && collect(o.html, candidates));
  s.doc.edits.forEach((e) => {
    if (e.html) collect(e.html, candidates);
    if (e.attrs?.class) e.attrs.class.split(/\s+/).forEach((c) => c && candidates.add(c));
  });
  Object.values(s.sources).forEach((src) => collect(src.source, candidates));
  frameDoc()?.querySelectorAll('[class]').forEach((el) => el.classList.forEach((c) => candidates.add(c)));

  const files: Record<string, string> = {
    'tailwindcss/theme.css': themeCss,
    'tailwindcss/utilities.css': utilitiesCss,
    'tailwindcss/preflight.css': preflightCss,
  };
  const input = [
    '@layer theme, base, components, utilities;',
    '@import "tailwindcss/theme.css" layer(theme);',
    preflight ? '@import "tailwindcss/preflight.css" layer(base);' : '',
    '@import "tailwindcss/utilities.css" layer(utilities);',
    s.doc.tailwind.config || '',
  ].join('\n');

  const compiler = await compile(input, {
    base: '/',
    loadStylesheet: async (id: string) => {
      if (!(id in files)) throw new Error(`Tailwind: cannot load ${id}`);
      return { path: id, base: '/', content: files[id] };
    },
  } as Parameters<typeof compile>[1]);
  return compiler.build(Array.from(candidates));
};
