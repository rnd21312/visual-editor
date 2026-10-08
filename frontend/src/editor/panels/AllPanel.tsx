import { useMemo, useState } from 'react';
import { cssToHex } from '../controls';
import { useEditor } from '../context';
import { useStyles } from './useStyles';

const MAX_ROWS = 400;

const isColorProp = (prop: string): boolean =>
  /(^|-)color$|^fill$|^stroke$|^caret-color$|^accent-color$/.test(prop);

/**
 * Every CSS property the browser resolved for the selected element, live. Edit any value to override it;
 * changed properties are highlighted and can be reset.
 */
export const AllPanel = () => {
  const { selected, frame, version } = useEditor();
  const { styles, set, computedStyle } = useStyles();
  const [filter, setFilter] = useState('');
  const [changedOnly, setChangedOnly] = useState(false);

  // `version` is bumped on a timer while an element is selected, so the values follow the page live.
  const names = useMemo(() => {
    if (!computedStyle) return [];
    return Array.from({ length: computedStyle.length }, (_, i) => computedStyle.item(i)).filter(
      (name) => !name.startsWith('-webkit-') && !name.startsWith('-moz-'),
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected, frame, version]);

  if (!selected || !computedStyle) return null;

  const needle = filter.trim().toLowerCase();
  const all = Array.from(new Set([...Object.keys(styles), ...names])).sort();
  const rows = all.filter(
    (prop) => (!changedOnly || prop in styles) && (needle === '' || prop.includes(needle)),
  );

  return (
    <div className="ve-panel-body">
      <div className="ve-all__bar">
        <input
          className="ve-input"
          placeholder={`Search ${all.length} properties…`}
          value={filter}
          spellCheck={false}
          onChange={(event) => setFilter(event.target.value)}
          aria-label="Search properties"
        />
        <label className="ve-all__only">
          <input type="checkbox" checked={changedOnly} onChange={(event) => setChangedOnly(event.target.checked)} />
          Changed
        </label>
      </div>

      <div className="ve-all">
        {rows.slice(0, MAX_ROWS).map((prop) => {
          const edited = prop in styles;
          const value = edited ? (styles[prop] ?? '') : computedStyle.getPropertyValue(prop).trim();
          const hex = isColorProp(prop) ? cssToHex(value) : null;

          return (
            <div key={prop} className={`ve-all__row ${edited ? 'is-touched' : ''}`}>
              <span className="ve-all__name" title={prop}>
                {prop}
                {edited && (
                  <button
                    type="button"
                    className="ve-reset"
                    onClick={() => set(prop, '')}
                    aria-label={`Reset ${prop}`}
                    title="Reset"
                  >
                    ×
                  </button>
                )}
              </span>
              <span className="ve-all__value">
                {hex && (
                  <input
                    type="color"
                    className="ve-swatch ve-swatch--sm"
                    value={hex}
                    aria-label={`${prop} picker`}
                    onChange={(event) => set(prop, event.target.value)}
                  />
                )}
                <input
                  className="ve-input"
                  value={value}
                  spellCheck={false}
                  aria-label={prop}
                  onChange={(event) => set(prop, event.target.value)}
                />
              </span>
            </div>
          );
        })}
        {rows.length > MAX_ROWS && <p className="ve-note">Showing the first {MAX_ROWS} — narrow it with the search.</p>}
        {rows.length === 0 && <p className="ve-note">No properties match.</p>}
      </div>
    </div>
  );
};
