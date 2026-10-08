import { useRef, useState } from 'react';
import type { Component, ComponentKind, Pos } from '@/lib/visualCss';
import { Section } from '../controls';
import { useEditor } from '../context';
import { armDrag } from '../DragLayer';
import { cleanHtml, SNIPPETS } from '../ops';
import { newId } from '../state';

const POSITIONS: { value: Pos; label: string; title: string }[] = [
  { value: 'after', label: 'After', title: 'Insert after the selected element' },
  { value: 'before', label: 'Before', title: 'Insert before the selected element' },
  { value: 'append', label: 'Inside end', title: 'Insert inside the selected element, at the end' },
  { value: 'prepend', label: 'Inside start', title: 'Insert inside the selected element, at the start' },
];

type CodeKind = ComponentKind | 'import';

const KINDS: { value: CodeKind; label: string; hint: string; placeholder: string }[] = [
  { value: 'html', label: 'HTML', hint: 'Plain markup. Scripts are ignored.', placeholder: '<section class="…">\n  <h2>Hello</h2>\n</section>' },
  {
    value: 'embed',
    label: 'Embed',
    hint: 'Paste an embed snippet (YouTube, maps, forms, chat widgets…). Its scripts run on the page.',
    placeholder: '<iframe src="https://www.youtube.com/embed/…" loading="lazy"></iframe>',
  },
  {
    value: 'jsonld',
    label: 'JSON-LD',
    hint: 'Structured data for search engines (FAQ, product, event…). Added as a script tag, nothing is shown.',
    placeholder: '{\n  "@context": "https://schema.org",\n  "@type": "FAQPage",\n  "mainEntity": []\n}',
  },
  {
    value: 'import',
    label: 'Import JSON',
    hint: 'Paste a components file exported from another site (or from “Export” below).',
    placeholder: '[{ "name": "Hero", "html": "<section>…</section>", "css": "" }]',
  },
];

const groups = Array.from(new Set(SNIPPETS.map((snippet) => snippet.group)));

/** JSON text → a script tag with the markup-safe form of that JSON, or an error message. */
const jsonLd = (code: string): { html: string } | { error: string } => {
  try {
    const data: unknown = JSON.parse(code);
    const safe = JSON.stringify(data).replace(/</g, '\\u003c');
    return { html: `<script type="application/ld+json">${safe}</script>` };
  } catch {
    return { error: 'This is not valid JSON yet.' };
  }
};

/** Add new tags, embed code, structured data and saved components: click to insert, or drag onto the page. */
export const AddPanel = () => {
  const { doc, selected, patchDoc, actions } = useEditor();
  const [kind, setKind] = useState<CodeKind>('html');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [editing, setEditing] = useState<string | null>(null);
  const [message, setMessage] = useState('');
  const nameRef = useRef<HTMLInputElement>(null);
  const components = doc.components ?? [];
  const current = KINDS.find((entry) => entry.value === kind) ?? KINDS[0];

  const updateComponent = (id: string, change: Partial<Component>) =>
    patchDoc(
      (state) => ({
        ...state,
        components: (state.components ?? []).map((item) => (item.id === id ? { ...item, ...change } : item)),
      }),
      `component:${id}`,
    );

  const addComponent = (html: string, label: string, componentKind: ComponentKind) => {
    const component: Component = { id: newId(), name: label.trim() || 'Component', html: cleanHtml(html.trim()), css: '', kind: componentKind };
    patchDoc((state) => ({ ...state, components: [...(state.components ?? []), component] }), '');
    setName('');
  };

  const removeComponent = (id: string) =>
    patchDoc((state) => ({ ...state, components: (state.components ?? []).filter((item) => item.id !== id) }), '');

  /** Markup for the code box, or null (with a message) when it cannot be used. */
  const markup = (): string | null => {
    if (kind === 'jsonld') {
      const result = jsonLd(code);
      if ('error' in result) {
        setMessage(result.error);
        return null;
      }
      return result.html;
    }
    return code.trim() === '' ? null : code;
  };

  const insertCode = () => {
    setMessage('');
    if (kind === 'import') return;
    const html = markup();
    if (html) actions.insertCode(kind, html, name || current?.label || 'Code');
  };

  const saveCode = () => {
    setMessage('');
    if (kind === 'import') return;
    const html = markup();
    if (!html) return;
    addComponent(html, name || (kind === 'jsonld' ? 'Structured data' : kind === 'embed' ? 'Embed' : 'Custom block'), kind);
    setCode('');
  };

  const importJson = () => {
    try {
      const data: unknown = JSON.parse(code);
      const list = Array.isArray(data) ? data : (data as { components?: unknown }).components;
      if (!Array.isArray(list)) throw new Error('shape');
      const added: Component[] = list
        .filter((item): item is Record<string, unknown> => typeof item === 'object' && item !== null)
        .map((item) => ({
          id: newId(),
          name: String(item.name ?? 'Imported'),
          html: cleanHtml(String(item.html ?? '')),
          css: String(item.css ?? ''),
          kind: (['html', 'embed', 'jsonld'] as const).find((k) => k === item.kind) ?? 'html',
        }))
        .filter((item) => item.html.trim() !== '');
      patchDoc((state) => ({ ...state, components: [...(state.components ?? []), ...added] }), '');
      setMessage(`Imported ${added.length} component${added.length === 1 ? '' : 's'}.`);
      setCode('');
    } catch {
      setMessage('Could not read that JSON. Expected a list of components or { "components": [...] }.');
    }
  };

  const exportJson = () => {
    const text = JSON.stringify(components.map(({ name: label, html, css, kind: type }) => ({ name: label, html, css, kind: type ?? 'html' })), null, 2);
    setKind('import');
    setCode(text);
    setMessage('Copy this JSON to reuse the components on another site.');
    void navigator.clipboard?.writeText(text).catch(() => undefined);
  };

  return (
    <div className="ve-panel-body">
      <div className="ve-add__where">
        <span>Insert</span>
        <div className="ve-segment" role="group" aria-label="Where to insert">
          {POSITIONS.map((entry) => (
            <button
              key={entry.value}
              type="button"
              title={entry.title}
              className={actions.insertAt === entry.value ? 'is-on' : ''}
              onClick={() => actions.setInsertAt(entry.value)}
            >
              {entry.label}
            </button>
          ))}
        </div>
      </div>
      <p className="ve-note">
        {selected ? 'Relative to the selected element.' : 'Nothing selected: goes at the end of the page content.'} Click
        to insert, or drag onto the page or the Elements list.
      </p>

      <Section title="Components" count={components.length} defaultOpen>
        {components.length === 0 && (
          <p className="ve-note">
            Nothing saved yet. Select an element and press “Save selected”, or write code below and “Save as component”.
          </p>
        )}
        {components.map((component) => (
          <div key={component.id} className="ve-comp">
            <div
              className="ve-comp__main"
              role="button"
              tabIndex={0}
              onClick={() => actions.insert(component.html, component.name, component.id, component.kind === 'embed')}
              onKeyDown={(event) => event.key === 'Enter' && actions.insert(component.html, component.name, component.id, component.kind === 'embed')}
              onPointerDown={(event) =>
                armDrag(event, () =>
                  actions.startDrag(event, { kind: 'new', html: component.html, label: component.name, comp: component.id }),
                )
              }
            >
              <strong>
                {component.name} {component.kind && component.kind !== 'html' && <span className="ve-kind">{component.kind === 'jsonld' ? 'JSON-LD' : 'embed'}</span>}
              </strong>
              <small>{component.html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 48) || '<…>'}</small>
            </div>
            <button type="button" className="ve-icon-btn" title="Edit code" onClick={() => setEditing(editing === component.id ? null : component.id)}>
              ✎
            </button>
            <button type="button" className="ve-icon-btn" title="Delete" onClick={() => removeComponent(component.id)}>
              ×
            </button>
            {editing === component.id && (
              <div className="ve-comp__edit">
                <input className="ve-input" value={component.name} aria-label="Component name" onChange={(event) => updateComponent(component.id, { name: event.target.value })} />
                <select
                  className="ve-input"
                  value={component.kind ?? 'html'}
                  aria-label="Component type"
                  onChange={(event) => updateComponent(component.id, { kind: event.target.value as ComponentKind })}
                >
                  <option value="html">HTML</option>
                  <option value="embed">Embed (scripts run)</option>
                  <option value="jsonld">JSON-LD</option>
                </select>
                <textarea className="ve-textarea ve-code" rows={6} spellCheck={false} value={component.html} aria-label="Component HTML" onChange={(event) => updateComponent(component.id, { html: event.target.value })} />
                <textarea className="ve-textarea ve-code" rows={4} spellCheck={false} placeholder="/* CSS for this component */" value={component.css} aria-label="Component CSS" onChange={(event) => updateComponent(component.id, { css: event.target.value })} />
                <p className="ve-hint">Changes apply to every place the component is used (after saving).</p>
              </div>
            )}
          </div>
        ))}

        <div className="ve-add ve-add--two">
          <input ref={nameRef} className="ve-input" placeholder="Name" value={name} aria-label="Component name" onChange={(event) => setName(event.target.value)} />
          <button
            type="button"
            className="ve-btn"
            disabled={!selected}
            title="Save the selected element as a reusable component"
            onClick={() => selected && addComponent(selected.outerHTML, name || selected.localName, 'html')}
          >
            Save selected
          </button>
        </div>
        {components.length > 0 && (
          <button type="button" className="ve-btn ve-btn--ghost" onClick={exportJson}>
            Export components as JSON
          </button>
        )}
      </Section>

      <Section title="Code, embed & JSON" defaultOpen>
        <div className="ve-segment" role="group" aria-label="Kind of code">
          {KINDS.map((entry) => (
            <button
              key={entry.value}
              type="button"
              className={kind === entry.value ? 'is-on' : ''}
              onClick={() => {
                setKind(entry.value);
                setMessage('');
              }}
            >
              {entry.label}
            </button>
          ))}
        </div>
        <p className="ve-hint">{current?.hint}</p>
        <textarea
          className="ve-textarea ve-code"
          rows={7}
          spellCheck={false}
          placeholder={current?.placeholder}
          value={code}
          aria-label="Code"
          onChange={(event) => setCode(event.target.value)}
        />
        {message && <p className="ve-hint ve-hint--warn">{message}</p>}
        {kind === 'import' ? (
          <button type="button" className="ve-btn ve-btn--primary" disabled={!code.trim()} onClick={importJson}>
            Import components
          </button>
        ) : (
          <div className="ve-add ve-add--two">
            <button type="button" className="ve-btn ve-btn--primary" disabled={!code.trim()} onClick={insertCode}>
              Insert
            </button>
            <button type="button" className="ve-btn" disabled={!code.trim()} onClick={saveCode}>
              Save as component
            </button>
          </div>
        )}
      </Section>

      {groups.map((group) => (
        <Section key={group} title={group} defaultOpen={group === 'Layout' || group === 'Text'}>
          <div className="ve-palette">
            {SNIPPETS.filter((snippet) => snippet.group === group).map((snippet) => (
              <button
                key={snippet.id}
                type="button"
                className="ve-chip"
                title={`Insert <${snippet.id}> — click or drag`}
                onClick={() => actions.insert(snippet.html, snippet.label)}
                onPointerDown={(event) =>
                  armDrag(event, () => actions.startDrag(event, { kind: 'new', html: snippet.html, label: snippet.label }))
                }
              >
                {snippet.label}
              </button>
            ))}
          </div>
        </Section>
      ))}
    </div>
  );
};
