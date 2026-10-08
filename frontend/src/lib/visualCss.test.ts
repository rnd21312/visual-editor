import { describe, expect, it } from 'vitest';
import {
  buildCss,
  compileEdit,
  editsForPage,
  emptyDoc,
  isEmptyEdit,
  pageKey,
  serializeDoc,
  templateSeoKey,
  type Edit,
} from './visualCss';

const edit = (over: Partial<Edit> = {}): Edit => ({
  id: 'a',
  scope: 'page',
  page: '/',
  sel: 'h1',
  label: 'h1',
  styles: {},
  css: '',
  ...over,
});

describe('pageKey', () => {
  it('normalises paths and strips the home path', () => {
    expect(pageKey('/')).toBe('/');
    expect(pageKey('/tours')).toBe('/tours/');
    expect(pageKey('/tours/phuket/')).toBe('/tours/phuket/');
    expect(pageKey('/shop/tours/', '/shop')).toBe('/tours/');
    expect(pageKey('/shop/', '/shop/')).toBe('/');
  });
});

describe('compileEdit', () => {
  it('emits !important declarations and media queries per device', () => {
    const css = compileEdit(
      edit({ styles: { base: { color: 'red' }, tablet: { 'font-size': '20px' }, mobile: { display: 'none' } } }),
    );

    expect(css).toContain('h1{color:red !important}');
    expect(css).toContain('@media (max-width: 1023px){h1{font-size:20px !important}}');
    expect(css).toContain('@media (max-width: 767px){h1{display:none !important}}');
  });

  it('does not double !important and skips blank values', () => {
    expect(compileEdit(edit({ styles: { base: { color: 'red !important', margin: '  ' } } }))).toBe(
      'h1{color:red !important}',
    );
  });

  it('replaces the selector keyword in custom CSS', () => {
    expect(compileEdit(edit({ css: 'selector:hover{opacity:.5}' }))).toBe('h1:hover{opacity:.5}');
  });

  it('compiles :hover styles, and lets the editor preview force them', () => {
    const hover = edit({ sel: 'a', states: { hover: { base: { color: 'red' }, mobile: { color: 'blue' } } } });

    expect(compileEdit(hover)).toBe(
      'a:hover{color:red !important}\n@media (max-width: 767px){a:hover{color:blue !important}}',
    );
    expect(compileEdit(hover, true)).toContain('a:hover,a[data-ve-force~="hover"]{color:red !important}');
    expect(isEmptyEdit(hover)).toBe(false);
  });

  it('hides on selected devices only', () => {
    const css = compileEdit(edit({ hide: { desktop: true, mobile: true } }));
    expect(css).toContain('(min-width: 1024px)');
    expect(css).toContain('(max-width: 767px)');
    expect(css).not.toContain('(min-width: 768px) and');
  });
});

describe('page scoping', () => {
  const doc = {
    ...emptyDoc(),
    css: '.x{top:0}',
    edits: [
      edit({ id: 'home', page: '/', styles: { base: { color: 'red' } } }),
      edit({ id: 'tours', page: '/tours/', sel: 'h2', styles: { base: { color: 'blue' } } }),
      edit({ id: 'site', scope: 'site', page: '/tours/', sel: 'footer', styles: { base: { color: 'green' } } }),
    ],
  };

  it('keeps site-wide edits and the ones for the page', () => {
    expect(editsForPage(doc, '/').map((e) => e.id)).toEqual(['home', 'site']);
    expect(editsForPage(doc, '/tours/').map((e) => e.id)).toEqual(['tours', 'site']);
  });

  it('puts global CSS first', () => {
    expect(buildCss(doc, '/').startsWith('.x{top:0}')).toBe(true);
  });

  it('serialises with compiled CSS', () => {
    expect(serializeDoc(doc).edits[0]?.compiled).toBe('h1{color:red !important}');
  });
});

describe('isEmptyEdit', () => {
  it('is empty only when nothing is set', () => {
    expect(isEmptyEdit(edit())).toBe(true);
    expect(isEmptyEdit(edit({ text: '' }))).toBe(false);
    expect(isEmptyEdit(edit({ css: 'a{}' }))).toBe(false);
    expect(isEmptyEdit(edit({ hide: { tablet: true } }))).toBe(false);
    expect(isEmptyEdit(edit({ styles: { mobile: { color: 'red' } } }))).toBe(false);
    expect(isEmptyEdit(edit({ attrs: { href: '/x' } }))).toBe(false);
  });
});

describe('template scope', () => {
  const tplEdit = (id: string, scope: Edit['scope'], extra: Partial<Edit> = {}) => edit({ id, scope, sel: id, styles: { base: { color: 'red' } }, ...extra });

  it('applies template edits only where the template matches', () => {
    const doc = {
      ...emptyDoc(),
      edits: [
        tplEdit('page', 'page', { page: '/a/' }),
        tplEdit('tpl', 'template', { tpl: 'single:product' }),
        tplEdit('site', 'site'),
      ],
    };

    expect(editsForPage(doc, '/a/', 'single:product').map((e) => e.id)).toEqual(['page', 'tpl', 'site']);
    expect(editsForPage(doc, '/b/', 'single:product').map((e) => e.id)).toEqual(['tpl', 'site']);
    expect(editsForPage(doc, '/b/', 'single:post').map((e) => e.id)).toEqual(['site']);
    expect(editsForPage(doc, '/b/').map((e) => e.id)).toEqual(['site']); // no template known: template edits stay out
  });

  it('prints template edits into the CSS of every item of the template', () => {
    const doc = { ...emptyDoc(), edits: [tplEdit('h1', 'template', { tpl: 'single:post' })] };
    expect(buildCss(doc, '/hello/', false, 'single:post')).toContain('h1{color:red !important}');
    expect(buildCss(doc, '/hello/', false, 'single:product')).toBe('');
  });

  it('keeps template SEO apart from page SEO', () => {
    expect(templateSeoKey('single:product')).toBe('tpl:single:product');
  });
});
