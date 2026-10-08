import type { KeyboardEvent } from 'react';
import { useEditor } from '../context';

/** Tab inserts two spaces instead of leaving the field. */
export const indentOnTab = (event: KeyboardEvent<HTMLTextAreaElement>, apply: (value: string) => void) => {
  if (event.key !== 'Tab') return;
  event.preventDefault();
  const field = event.currentTarget;
  const { selectionStart: start, selectionEnd: end, value } = field;
  apply(`${value.slice(0, start)}  ${value.slice(end)}`);
  requestAnimationFrame(() => field.setSelectionRange(start + 2, start + 2));
};

/** Free CSS for the selected element. `selector` stands for the rule's selector. */
export const CssPanel = () => {
  const { activeEdit, target, patch } = useEditor();
  const sel = activeEdit?.sel ?? target?.sel ?? '';

  const set = (css: string) => patch((edit) => ({ ...edit, css }), `css:${sel}`);

  return (
    <div className="ve-panel-body">
      <p className="ve-note">
        Write any CSS. Use <code>selector</code> for the selected element — hover states, pseudo-elements, animations,
        media queries and so on.
      </p>
      <textarea
        className="ve-textarea ve-code ve-code--tall"
        spellCheck={false}
        value={activeEdit?.css ?? ''}
        placeholder={'selector {\n  color: red;\n}\n\nselector:hover {\n  transform: scale(1.04);\n}'}
        onChange={(event) => set(event.target.value)}
        onKeyDown={(event) => indentOnTab(event, set)}
        aria-label="Custom CSS for this element"
      />
      <div className="ve-hint">
        <code>selector</code> → <code>{sel || '—'}</code>
      </div>
    </div>
  );
};
