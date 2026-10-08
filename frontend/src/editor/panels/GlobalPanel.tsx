import { useMemo } from 'react';
import { ColorInput, Row } from '../controls';
import { useEditor } from '../context';
import { readTokens } from '../fields';
import { blankEdit } from '../state';
import { indentOnTab } from './CssPanel';

const TOKENS_ID = 'site-tokens';

/** Whole-site settings: the brand colour variables and CSS that applies everywhere. */
export const GlobalPanel = () => {
  const { doc, frame, patchDoc, version } = useEditor();

  const tokens = useMemo(
    () => (frame ? readTokens(frame.doc, frame.win) : []),
    // Colours can change as edits apply.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [frame, version],
  );
  const overrides = doc.edits.find((edit) => edit.id === TOKENS_ID)?.styles.base ?? {};

  const setToken = (name: string, value: string) =>
    patchDoc((current) => {
      const existing = current.edits.find((edit) => edit.id === TOKENS_ID);
      const base = existing ?? { ...blankEdit({ sel: ':root', label: 'Brand colours', scope: 'site', page: '/' }), id: TOKENS_ID };
      const styles = { ...(base.styles.base ?? {}) };
      if (value.trim() === '') delete styles[name];
      else styles[name] = value;
      const next = { ...base, styles: Object.keys(styles).length > 0 ? { base: styles } : {} };
      return {
        ...current,
        edits: existing ? current.edits.map((edit) => (edit.id === TOKENS_ID ? next : edit)) : [...current.edits, next],
      };
    }, `token:${name}`);

  const setCss = (css: string) => patchDoc((current) => ({ ...current, css }), 'global-css');

  return (
    <div className="ve-panel-body">
      <div className="ve-group-title">Theme colours</div>
      <p className="ve-note">Colour variables the theme declares. Change one and it changes everywhere the site uses it.</p>
      {tokens.map((token) => (
        <Row
          key={token.name}
          label={token.name.replace(/^--(wp--preset--color--|stz-)?/, '')}
          touched={token.name in overrides}
          onReset={() => setToken(token.name, '')}
        >
          <ColorInput
            value={overrides[token.name]}
            computed={token.color}
            onChange={(value) => setToken(token.name, value)}
            label={token.name}
          />
        </Row>
      ))}

      <div className="ve-group-title">Site CSS</div>
      <p className="ve-note">CSS added here applies to every page.</p>
      <textarea
        className="ve-textarea ve-code ve-code--tall"
        spellCheck={false}
        value={doc.css}
        placeholder={'.my-class {\n  color: red;\n}'}
        onChange={(event) => setCss(event.target.value)}
        onKeyDown={(event) => indentOnTab(event, setCss)}
        aria-label="Site-wide CSS"
      />
    </div>
  );
};
