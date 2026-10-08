import { useState } from 'react';
import type { HideKey } from '@/lib/visualCss';
import type { Edit } from '@/lib/visualCss';
import { Row, Section } from '../controls';
import { useEditor } from '../context';
import { hasElementChildren } from '../selector';

const HIDE: { key: HideKey; label: string }[] = [
  { key: 'desktop', label: 'Desktop' },
  { key: 'tablet', label: 'Tablet' },
  { key: 'mobile', label: 'Mobile' },
];

const without = <K extends keyof Edit>(edit: Edit, ...keys: K[]): Edit => {
  const copy = { ...edit };
  keys.forEach((key) => delete copy[key]);
  return copy;
};

/** Attributes worth a dedicated field per tag; everything else is in "All attributes". */
const QUICK: Record<string, { name: string; label: string }[]> = {
  a: [
    { name: 'href', label: 'Link URL' },
    { name: 'target', label: 'Target' },
    { name: 'title', label: 'Title' },
  ],
  img: [
    { name: 'src', label: 'Image URL' },
    { name: 'alt', label: 'Alt text' },
  ],
  source: [{ name: 'src', label: 'URL' }],
  video: [
    { name: 'src', label: 'Video URL' },
    { name: 'poster', label: 'Poster URL' },
  ],
  input: [
    { name: 'placeholder', label: 'Placeholder' },
    { name: 'value', label: 'Value' },
  ],
  textarea: [{ name: 'placeholder', label: 'Placeholder' }],
  button: [{ name: 'title', label: 'Title' }],
};

export const ContentPanel = () => {
  const { selected, activeEdit, target, patch } = useEditor();
  const [attrName, setAttrName] = useState('');
  const [attrValue, setAttrValue] = useState('');

  if (!selected) return null;

  const tag = selected.localName;
  const withChildren = hasElementChildren(selected);
  const key = (name: string) => `content:${target?.sel ?? ''}:${name}`;

  const attrs = activeEdit?.attrs ?? {};
  const attrValueOf = (name: string): string => attrs[name] ?? selected.getAttribute(name) ?? '';

  const setAttr = (name: string, value: string | null) =>
    patch((edit) => {
      const next: Record<string, string | null> = { ...(edit.attrs ?? {}) };
      next[name] = value;

      // An image keeps loading from its srcset unless that is cleared too.
      if (tag === 'img' && name === 'src') {
        next.srcset = null;
        next.sizes = null;
      }
      return { ...edit, attrs: next };
    }, key(`attr:${name}`));

  const dropAttr = (name: string) =>
    patch((edit) => {
      const next = { ...(edit.attrs ?? {}) };
      delete next[name];
      if (tag === 'img' && name === 'src') {
        delete next.srcset;
        delete next.sizes;
      }
      return Object.keys(next).length > 0 ? { ...edit, attrs: next } : without(edit, 'attrs');
    }, key(`drop:${name}`));

  const setText = (text: string) =>
    patch((edit) => ({ ...without(edit, 'html'), text }), key('text'));

  const setHtml = (html: string) =>
    patch((edit) => ({ ...without(edit, 'text'), html }), key('html'));

  const resetContent = () =>
    patch((edit) => without(edit, 'text', 'html'), key('reset'));

  const setHide = (hideKey: HideKey, on: boolean) =>
    patch((edit) => {
      const hide = { ...(edit.hide ?? {}) };
      if (on) hide[hideKey] = true;
      else delete hide[hideKey];
      return { ...edit, hide };
    }, key(`hide:${hideKey}`));

  const addAttr = () => {
    const name = attrName.trim().toLowerCase();
    if (!/^[a-z][a-z0-9_-]*$/.test(name)) return;
    setAttr(name, attrValue);
    setAttrName('');
    setAttrValue('');
  };

  const contentChanged = activeEdit?.text !== undefined || activeEdit?.html !== undefined;
  const quick = QUICK[tag] ?? [];
  const quickNames = new Set(quick.map((field) => field.name));
  const otherAttrs = Object.keys(attrs).filter((name) => !quickNames.has(name) && name !== 'srcset' && name !== 'sizes');

  return (
    <div className="ve-panel-body">
      <Section title="Text" defaultOpen>
        {withChildren ? (
          <p className="ve-note">
            This element contains other elements, so its text is part of the HTML below. Select a child element to edit
            only its text.
          </p>
        ) : (
          <textarea
            className="ve-textarea"
            rows={4}
            value={activeEdit?.text ?? selected.textContent ?? ''}
            onChange={(event) => setText(event.target.value)}
            aria-label="Text content"
          />
        )}
        <div className="ve-hint">Tip: double-click any text in the page to type on it directly.</div>
        {contentChanged && (
          <button type="button" className="ve-btn ve-btn--ghost" onClick={resetContent}>
            Restore original content
          </button>
        )}
      </Section>

      {quick.length > 0 && (
        <Section title={tag === 'a' ? 'Link' : tag === 'img' ? 'Image' : 'Properties'} defaultOpen>
          {quick.map((field) => (
            <Row
              key={field.name}
              label={field.label}
              touched={field.name in attrs}
              onReset={() => dropAttr(field.name)}
            >
              {field.name === 'target' ? (
                <select
                  className="ve-input"
                  value={attrValueOf('target')}
                  onChange={(event) => setAttr('target', event.target.value === '' ? null : event.target.value)}
                  aria-label="Link target"
                >
                  <option value="">Same tab</option>
                  <option value="_blank">New tab</option>
                </select>
              ) : (
                <input
                  className="ve-input"
                  value={attrValueOf(field.name)}
                  spellCheck={false}
                  onChange={(event) => setAttr(field.name, event.target.value)}
                  aria-label={field.label}
                />
              )}
            </Row>
          ))}
        </Section>
      )}

      <Section title="Responsive visibility" defaultOpen>
        <div className="ve-checks">
          {HIDE.map(({ key: hideKey, label }) => (
            <label key={hideKey}>
              <input
                type="checkbox"
                checked={Boolean(activeEdit?.hide?.[hideKey])}
                onChange={(event) => setHide(hideKey, event.target.checked)}
              />
              Hide on {label.toLowerCase()}
            </label>
          ))}
        </div>
      </Section>

      <Section title="All attributes" count={otherAttrs.length}>
        {Array.from(selected.attributes)
          .filter((attr) => !quickNames.has(attr.name) && attr.name !== 'style' && !attr.name.startsWith('on'))
          .map((attr) => (
            <Row
              key={attr.name}
              label={attr.name}
              touched={attr.name in attrs}
              onReset={() => dropAttr(attr.name)}
            >
              <input
                className="ve-input"
                value={attrValueOf(attr.name)}
                spellCheck={false}
                onChange={(event) => setAttr(attr.name, event.target.value)}
                aria-label={attr.name}
              />
            </Row>
          ))}
        <div className="ve-add">
          <input
            className="ve-input"
            placeholder="attribute (e.g. title)"
            value={attrName}
            spellCheck={false}
            onChange={(event) => setAttrName(event.target.value)}
          />
          <input
            className="ve-input"
            placeholder="value"
            value={attrValue}
            spellCheck={false}
            onChange={(event) => setAttrValue(event.target.value)}
            onKeyDown={(event) => event.key === 'Enter' && addAttr()}
          />
          <button type="button" className="ve-btn" onClick={addAttr}>
            Add
          </button>
        </div>
      </Section>

      <Section title="HTML" defaultOpen={withChildren}>
        <textarea
          className="ve-textarea ve-code"
          rows={8}
          spellCheck={false}
          value={activeEdit?.html ?? selected.innerHTML}
          onChange={(event) => setHtml(event.target.value)}
          aria-label="Inner HTML"
        />
        <div className="ve-hint">
          Replaces everything inside the element. Scripts and unsafe markup are removed when you save.
        </div>
      </Section>
    </div>
  );
};
