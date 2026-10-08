import { useMemo, useState } from 'react';
import { useEditor } from '../context';
import { classSelector, isEditorNode, isPlaceholder } from '../selector';

/**
 * Every CSS class used on the page. Click one to edit its rule: the change applies to every element that has
 * the class (all buttons sharing `.btn`, every card, …) and, by default, to the whole site.
 */
export const ClassesPanel = () => {
  const { frame, doc, version, selected, actions } = useEditor();
  const [filter, setFilter] = useState('');
  const [name, setName] = useState('');

  const counts = useMemo(() => {
    const map = new Map<string, number>();
    if (!frame) return map;
    frame.doc.body?.querySelectorAll('[class]').forEach((el) => {
      if (isEditorNode(el) || isPlaceholder(el)) return;
      for (const cls of Array.from(el.classList)) map.set(cls, (map.get(cls) ?? 0) + 1);
    });
    return map;
    // `version` follows DOM changes in the preview.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [frame, version]);

  const styled = useMemo(() => new Set(doc.edits.map((edit) => edit.sel)), [doc.edits]);

  const needle = filter.trim().toLowerCase().replace(/^\./, '');
  const rows = Array.from(counts.entries())
    .filter(([cls]) => needle === '' || cls.toLowerCase().includes(needle))
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]));

  const create = () => {
    const clean = name.trim().replace(/^\./, '').replace(/[^\w-]/g, '-');
    if (!clean || !selected) return;
    actions.addClass(clean);
    setName('');
  };

  return (
    <div className="ve-panel-body">
      <p className="ve-note">
        Pick a class to style <b>every element that uses it</b> at once — for example all buttons with the same class.
      </p>

      <div className="ve-classes__new">
        <input
          className="ve-input"
          placeholder="New class name, e.g. btn-primary"
          value={name}
          spellCheck={false}
          onChange={(event) => setName(event.target.value)}
          onKeyDown={(event) => event.key === 'Enter' && create()}
          aria-label="New class name"
        />
        <button type="button" className="ve-btn" disabled={!selected || !name.trim()} onClick={create} title="Add this class to the selected element and edit it">
          Add to selected
        </button>
      </div>
      {!selected && <p className="ve-hint">Select an element first to give it a new class.</p>}

      <div className="ve-all__bar">
        <input
          className="ve-input"
          placeholder={`Search ${counts.size} classes…`}
          value={filter}
          spellCheck={false}
          onChange={(event) => setFilter(event.target.value)}
          aria-label="Search classes"
        />
      </div>

      <ul className="ve-classes">
        {rows.slice(0, 300).map(([cls, count]) => (
          <li key={cls}>
            <button type="button" onClick={() => actions.editClass(cls)} title={`Edit .${cls} (${count} elements)`}>
              <code>.{cls}</code>
              {styled.has(classSelector(cls)) && <span className="ve-dot" title="Has your changes" />}
              <span className="ve-classes__count">{count}</span>
            </button>
          </li>
        ))}
        {rows.length === 0 && <li className="ve-note">No classes match.</li>}
      </ul>
    </div>
  );
};
