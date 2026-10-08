import { useMemo, useState } from 'react';
import { classSelector, classesOf, matchCount, selectorChoices } from '../selector';
import { useEditor } from '../context';

/** What is selected, what you can do with it, where an edit applies and which selector it uses. */
export const RuleBar = () => {
  const { selected, target, activeEdit, matching, frame, state, setState, patch, setActiveId, removeEdit, setScope, template, actions } =
    useEditor();

  const [newClass, setNewClass] = useState('');
  const [allClasses, setAllClasses] = useState(false);
  const choices = useMemo(() => (selected ? selectorChoices(selected) : []), [selected]);
  if (!selected || !target) return null;

  const sel = activeEdit?.sel ?? target.sel;
  const scope = activeEdit?.scope ?? target.scope;
  const count = frame ? matchCount(frame.doc, sel) : 0;
  const pageSpecific =
    scope !== 'page' && sel.includes(':nth-child(') && !/^(header|footer|\[data-ve-id)/.test(sel) && !(scope === 'template' && template.templated);

  const setSelector = (next: string) => patch((edit) => ({ ...edit, sel: next }), `sel:${target.sel}`);

  return (
    <div className="ve-rule">
      <div className="ve-rule__top">
        <div className="ve-rule__name" title={target.label}>
          <span className="ve-tag">{selected.localName}</span>
          <span>{target.label.slice(selected.localName.length) || ' '}</span>
        </div>
        <div className="ve-tools" role="toolbar" aria-label="Element actions">
          <button type="button" title="Move up (swap with previous sibling)" onClick={() => actions.moveBy(-1)}>
            ↑
          </button>
          <button type="button" title="Move down (swap with next sibling)" onClick={() => actions.moveBy(1)}>
            ↓
          </button>
          <button type="button" title="Duplicate (Ctrl+D)" onClick={actions.duplicate}>
            ⧉
          </button>
          <button type="button" className="is-danger" title="Delete element (Delete key)" onClick={actions.remove}>
            🗑
          </button>
          <button type="button" title="Deselect (Esc)" onClick={actions.deselect}>
            ✕
          </button>
        </div>
      </div>

      <div className="ve-classes-bar">
        <span className="ve-classes-bar__label" title="Edit a class to change every element that uses it">
          Classes
        </span>
        {(allClasses ? classesOf(selected) : classesOf(selected).slice(0, 6)).map((name) => (
          <span key={name} className={`ve-class ${sel === classSelector(name) ? 'is-on' : ''}`}>
            <button
              type="button"
              className="ve-class__name"
              title={`Edit .${name} — applies to ${frame ? matchCount(frame.doc, classSelector(name)) : '?'} elements`}
              onClick={() => actions.editClass(name)}
            >
              .{name.length > 22 ? `${name.slice(0, 22)}…` : name}
            </button>
            <button type="button" className="ve-class__x" aria-label={`Remove class ${name} from this element`} title="Remove this class from the element" onClick={() => actions.removeClass(name)}>
              ×
            </button>
          </span>
        ))}
        {classesOf(selected).length > 6 && (
          <button type="button" className="ve-btn ve-btn--ghost ve-mini" onClick={() => setAllClasses(!allClasses)}>
            {allClasses ? 'fewer' : `+${classesOf(selected).length - 6}`}
          </button>
        )}
        <input
          className="ve-input ve-classes-bar__new"
          placeholder="+ class"
          value={newClass}
          spellCheck={false}
          aria-label="Add a class to this element"
          onChange={(event) => setNewClass(event.target.value)}
          onKeyDown={(event) => {
            if (event.key !== 'Enter') return;
            const clean = newClass.trim().replace(/^\./, '').replace(/[^\w-]/g, '-');
            if (clean) actions.addClass(clean);
            setNewClass('');
          }}
        />
      </div>

      <div className="ve-rule__line">
        <div className="ve-seg2" role="group" aria-label="Where this edit applies">
          <button type="button" className={scope === 'page' ? 'is-on' : ''} onClick={() => setScope('page')} title="Only this URL">
            This page
          </button>
          {template.templated && (
            <button
              type="button"
              className={scope === 'template' ? 'is-on' : ''}
              onClick={() => setScope('template')}
              title={`Every item that uses the "${template.label}"${template.count ? ` (${template.count})` : ''}`}
            >
              This template
            </button>
          )}
          <button type="button" className={scope === 'site' ? 'is-on' : ''} onClick={() => setScope('site')} title="Every page of the site">
            Whole site
          </button>
        </div>
        {activeEdit && (
          <button type="button" className="ve-btn ve-btn--ghost" onClick={() => removeEdit(activeEdit.id)}>
            Reset all
          </button>
        )}
      </div>

      {pageSpecific && (
        <div className="ve-hint ve-hint--warn">
          This selector is tied to this page’s layout, so it may not match elsewhere. For a change that reaches other pages pick a
          class or tag selector below (or select the header/footer).
        </div>
      )}

      {matching.length > 1 && (
        <select
          className="ve-input"
          value={activeEdit?.id ?? ''}
          onChange={(event) => setActiveId(event.target.value)}
          aria-label="Rule being edited"
        >
          {matching.map((edit) => (
            <option key={edit.id} value={edit.id}>
              {edit.label || edit.sel}
            </option>
          ))}
        </select>
      )}

      <div className="ve-states" role="group" aria-label="State being edited">
        {(['normal', 'hover', 'focus', 'active'] as const).map((name) => (
          <button key={name} type="button" className={state === name ? 'is-on' : ''} onClick={() => setState(name)}>
            {name === 'normal' ? 'Normal' : `:${name}`}
          </button>
        ))}
      </div>

      <div className="ve-rule__sel">
        <input
          className="ve-input ve-code"
          value={sel}
          spellCheck={false}
          onChange={(event) => setSelector(event.target.value)}
          list="ve-selector-choices"
          aria-label="CSS selector"
        />
        <datalist id="ve-selector-choices">
          {choices.map((choice) => (
            <option key={choice} value={choice} />
          ))}
        </datalist>
        <span className={`ve-count ${count < 1 ? 'is-bad' : ''}`} title="Elements matching this selector">
          {count < 0 ? 'invalid' : `${count} match${count === 1 ? '' : 'es'}`}
        </span>
      </div>
      <div className="ve-hint">Pick a shorter selector (a class or tag) to change every matching element at once.</div>
    </div>
  );
};
