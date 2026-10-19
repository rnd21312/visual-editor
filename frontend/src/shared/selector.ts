// Short stable CSS selector of an element (same rules as src/Inspector.php):
// builder id → unique id → header/footer/main/nav → unique class → path of :nth-child(k of :not([data-ve-id])).

const STATE_CLASS = /^(active|current|open|show|is-|has-|focus|hover|selected|current-menu|current_page|menu-item-\d|page-item-\d|wp-image-\d|post-\d|sve-|swiper-slide-(active|next|prev))/;

const esc = (s: string) => (typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(s) : s.replace(/([^\w-])/g, '\\$1'));

const count = (doc: Document, sel: string): number => {
  try {
    return doc.querySelectorAll(sel).length;
  } catch {
    return 0;
  }
};

const uniquePart = (el: Element, doc: Document): string | null => {
  const sid = el.getAttribute('data-sid');
  if (sid) return `[data-sid="${sid}"]`;
  const name = el.localName;
  const id = el.id;
  if (id && /^[A-Za-z][\w-]*$/.test(id) && !/\d{3,}|^(radix|headlessui|react|ember|ui-id)/i.test(id) && count(doc, '#' + esc(id)) === 1) return '#' + esc(id);
  if (['header', 'footer', 'main', 'nav'].includes(name) && count(doc, name) === 1) return name;
  const classes = Array.from(el.classList).filter((c) => /^[A-Za-z_-][\w-]*$/.test(c) && !STATE_CLASS.test(c));
  for (const c of classes.slice(0, 6)) {
    if (count(doc, '.' + esc(c)) === 1) return '.' + esc(c);
    if (count(doc, name + '.' + esc(c)) === 1) return name + '.' + esc(c);
  }
  if (classes.length >= 2) {
    const pair = `${name}.${esc(classes[0])}.${esc(classes[1])}`;
    if (count(doc, pair) === 1) return pair;
  }
  return null;
};

export const selectorFor = (el: Element): string => {
  const doc = el.ownerDocument;
  const parts: string[] = [];
  let node: Element | null = el;
  while (node && node.nodeType === 1) {
    const name = node.localName;
    if (name === 'html' || name === 'body') {
      parts.unshift(name);
      break;
    }
    const unique = uniquePart(node, doc);
    if (unique) {
      parts.unshift(unique);
      break;
    }
    const parent: Element | null = node.parentElement;
    if (!parent) break;
    let index = 0;
    for (const sibling of Array.from(parent.children)) {
      if (!sibling.hasAttribute('data-ve-id')) index++;
      if (sibling === node) break;
    }
    parts.unshift(`${name}:nth-child(${index} of :not([data-ve-id]))`);
    node = parent;
  }
  return parts.join(' > ');
};

/** Selectors that would match the same "kind" of element (for "apply to all similar"). */
export const classSelector = (el: Element): string | null => {
  const classes = Array.from(el.classList).filter((c) => /^[A-Za-z_-][\w-]*$/.test(c) && !STATE_CLASS.test(c));
  return classes.length ? el.localName + classes.slice(0, 2).map((c) => '.' + esc(c)).join('') : null;
};

export const describe = (el: Element): string => {
  const cls = Array.from(el.classList).filter((c) => !c.startsWith('sve-')).slice(0, 2);
  return el.localName + (el.id ? '#' + el.id : '') + (cls.length ? '.' + cls.join('.') : '');
};
