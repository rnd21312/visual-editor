/** The properties the Style panel offers. Anything else can be added as a free-form property. */

export type Field =
  | { kind: 'text'; prop: string; label: string; list?: string[] }
  | { kind: 'select'; prop: string; label: string; options: string[] }
  | { kind: 'color'; prop: string; label: string }
  | { kind: 'segment'; prop: string; label: string; options: { value: string; label: string; title?: string }[] }
  | { kind: 'box'; label: string; props: [string, string, string, string] };

export type SectionDef = { id: string; title: string; open?: boolean; fields: Field[] };

export const SECTIONS: SectionDef[] = [
  {
    id: 'spacing',
    title: 'Spacing',
    open: true,
    fields: [
      { kind: 'box', label: 'Margin', props: ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'] },
      { kind: 'box', label: 'Padding', props: ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'] },
    ],
  },
  {
    id: 'typography',
    title: 'Typography',
    open: true,
    fields: [
      { kind: 'color', prop: 'color', label: 'Text colour' },
      {
        kind: 'text',
        prop: 'font-family',
        label: 'Font',
        list: ['inherit', 'system-ui, sans-serif', 'Georgia, serif', 'ui-monospace, monospace'],
      },
      { kind: 'text', prop: 'font-size', label: 'Size' },
      {
        kind: 'select',
        prop: 'font-weight',
        label: 'Weight',
        options: ['100', '200', '300', '400', '500', '600', '700', '800', '900'],
      },
      { kind: 'text', prop: 'line-height', label: 'Line height' },
      { kind: 'text', prop: 'letter-spacing', label: 'Letter spacing' },
      {
        kind: 'segment',
        prop: 'text-align',
        label: 'Align',
        options: [
          { value: 'left', label: '⇤', title: 'left' },
          { value: 'center', label: '↔', title: 'center' },
          { value: 'right', label: '⇥', title: 'right' },
          { value: 'justify', label: '☰', title: 'justify' },
        ],
      },
      { kind: 'select', prop: 'text-transform', label: 'Case', options: ['none', 'uppercase', 'lowercase', 'capitalize'] },
      {
        kind: 'select',
        prop: 'text-decoration',
        label: 'Decoration',
        options: ['none', 'underline', 'line-through', 'overline'],
      },
      { kind: 'select', prop: 'font-style', label: 'Style', options: ['normal', 'italic'] },
      { kind: 'select', prop: 'white-space', label: 'Wrapping', options: ['normal', 'nowrap', 'pre-wrap', 'balance'] },
      { kind: 'text', prop: 'text-shadow', label: 'Text shadow' },
    ],
  },
  {
    id: 'background',
    title: 'Background',
    fields: [
      { kind: 'color', prop: 'background-color', label: 'Colour' },
      { kind: 'text', prop: 'background-image', label: 'Image / gradient' },
      { kind: 'select', prop: 'background-size', label: 'Size', options: ['cover', 'contain', 'auto', '100% 100%'] },
      { kind: 'text', prop: 'background-position', label: 'Position', list: ['center', 'top', 'bottom', 'left', 'right'] },
      { kind: 'select', prop: 'background-repeat', label: 'Repeat', options: ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'] },
      { kind: 'select', prop: 'background-attachment', label: 'Attachment', options: ['scroll', 'fixed'] },
    ],
  },
  {
    id: 'border',
    title: 'Border & radius',
    fields: [
      { kind: 'text', prop: 'border-width', label: 'Width' },
      {
        kind: 'select',
        prop: 'border-style',
        label: 'Style',
        options: ['none', 'solid', 'dashed', 'dotted', 'double'],
      },
      { kind: 'color', prop: 'border-color', label: 'Colour' },
      { kind: 'text', prop: 'border-radius', label: 'Radius' },
      { kind: 'text', prop: 'outline', label: 'Outline' },
    ],
  },
  {
    id: 'size',
    title: 'Size',
    fields: [
      { kind: 'text', prop: 'width', label: 'Width' },
      { kind: 'text', prop: 'height', label: 'Height' },
      { kind: 'text', prop: 'min-width', label: 'Min width' },
      { kind: 'text', prop: 'max-width', label: 'Max width' },
      { kind: 'text', prop: 'min-height', label: 'Min height' },
      { kind: 'text', prop: 'max-height', label: 'Max height' },
      { kind: 'text', prop: 'aspect-ratio', label: 'Aspect ratio' },
      { kind: 'select', prop: 'object-fit', label: 'Image fit', options: ['cover', 'contain', 'fill', 'none', 'scale-down'] },
      { kind: 'text', prop: 'object-position', label: 'Image position' },
    ],
  },
  {
    id: 'layout',
    title: 'Layout',
    fields: [
      {
        kind: 'select',
        prop: 'display',
        label: 'Display',
        options: ['block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'inline', 'inline-block', 'contents', 'none'],
      },
      { kind: 'select', prop: 'flex-direction', label: 'Direction', options: ['row', 'row-reverse', 'column', 'column-reverse'] },
      { kind: 'select', prop: 'flex-wrap', label: 'Wrap', options: ['nowrap', 'wrap', 'wrap-reverse'] },
      {
        kind: 'select',
        prop: 'justify-content',
        label: 'Justify',
        options: ['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly'],
      },
      {
        kind: 'select',
        prop: 'align-items',
        label: 'Align items',
        options: ['stretch', 'flex-start', 'center', 'flex-end', 'baseline'],
      },
      { kind: 'text', prop: 'gap', label: 'Gap' },
      { kind: 'text', prop: 'grid-template-columns', label: 'Grid columns', list: ['repeat(2, 1fr)', 'repeat(3, 1fr)', 'repeat(4, 1fr)'] },
      { kind: 'text', prop: 'flex', label: 'Flex (child)' },
      { kind: 'text', prop: 'order', label: 'Order' },
      { kind: 'select', prop: 'align-self', label: 'Align self', options: ['auto', 'flex-start', 'center', 'flex-end', 'stretch'] },
      { kind: 'select', prop: 'overflow', label: 'Overflow', options: ['visible', 'hidden', 'auto', 'scroll', 'clip'] },
    ],
  },
  {
    id: 'position',
    title: 'Position',
    fields: [
      { kind: 'select', prop: 'position', label: 'Position', options: ['static', 'relative', 'absolute', 'fixed', 'sticky'] },
      { kind: 'box', label: 'Offset', props: ['top', 'right', 'bottom', 'left'] },
      { kind: 'text', prop: 'z-index', label: 'Z-index' },
    ],
  },
  {
    id: 'effects',
    title: 'Effects',
    fields: [
      { kind: 'text', prop: 'opacity', label: 'Opacity' },
      { kind: 'text', prop: 'box-shadow', label: 'Shadow', list: ['0 10px 30px rgba(0,0,0,.15)', '0 2px 8px rgba(0,0,0,.12)', 'none'] },
      { kind: 'text', prop: 'transform', label: 'Transform', list: ['scale(1.05)', 'translateY(-4px)', 'rotate(2deg)'] },
      { kind: 'text', prop: 'transition', label: 'Transition', list: ['all .3s ease'] },
      { kind: 'text', prop: 'filter', label: 'Filter', list: ['grayscale(1)', 'blur(4px)', 'brightness(.8)'] },
      { kind: 'text', prop: 'backdrop-filter', label: 'Backdrop filter', list: ['blur(12px)'] },
      { kind: 'select', prop: 'cursor', label: 'Cursor', options: ['default', 'pointer', 'text', 'not-allowed', 'grab'] },
      { kind: 'select', prop: 'visibility', label: 'Visibility', options: ['visible', 'hidden'] },
      { kind: 'select', prop: 'pointer-events', label: 'Pointer events', options: ['auto', 'none'] },
    ],
  },
];

/** Every property that already has a control, so the free-form list only shows the rest. */
export const KNOWN_PROPS: Set<string> = new Set(
  SECTIONS.flatMap((section) => section.fields.flatMap((field) => (field.kind === 'box' ? field.props : [field.prop]))),
);

const COLOR_NAME = /color|colour|bg|background|fg|primary|secondary|accent|brand|surface|ink|text|border|forest|gold|sand|cream|muted|mist|blush|clay|black|white|gray|grey/i;

/**
 * Colour custom properties the page declares on :root / html / body (any theme: WordPress presets,
 * Tailwind, Bootstrap, hand written …), with their resolved value for the swatches.
 */
export const readTokens = (doc: Document, win: Window & typeof globalThis): { name: string; color: string }[] => {
  const names = new Set<string>();

  const scan = (rules: CSSRuleList) => {
    for (const rule of Array.from(rules)) {
      if ('selectorText' in rule) {
        const styleRule = rule as CSSStyleRule;
        if (!/(^|[\s,])(:root|html|body)\b/.test(styleRule.selectorText)) continue;
        for (const prop of Array.from(styleRule.style)) if (prop.startsWith('--')) names.add(prop);
      } else if ('cssRules' in rule) {
        scan((rule as CSSGroupingRule).cssRules);
      }
    }
  };

  for (const sheet of Array.from(doc.styleSheets)) {
    try {
      scan(sheet.cssRules);
    } catch {
      // Cross-origin stylesheet: its rules cannot be read.
    }
  }

  const style = win.getComputedStyle(doc.documentElement);
  return Array.from(names)
    .map((name) => ({ name, color: style.getPropertyValue(name).trim() }))
    .filter((token) => !/^--wp-(admin|bound|block-synced|editor)/.test(token.name))
    .filter((token) => token.color !== '' && COLOR_NAME.test(token.name) && win.CSS.supports('color', token.color))
    .slice(0, 60);
};
