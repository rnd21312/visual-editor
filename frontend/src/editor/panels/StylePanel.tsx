import { useMemo, useState } from 'react';
import { ColorInput, Row, Section, Segmented, SelectInput, ValueInput } from '../controls';
import { useEditor } from '../context';
import { useStyles } from './useStyles';
import { KNOWN_PROPS, readTokens, SECTIONS, type Field } from '../fields';

export const StylePanel = () => {
  const { device, state, frame } = useEditor();
  const { styles, set, computed } = useStyles();

  const tokens = useMemo(() => (frame ? readTokens(frame.doc, frame.win) : []), [frame]);

  const renderField = (field: Field) => {
    if (field.kind === 'box') {
      return (
        <Row key={field.label} label={field.label}>
          <div className="ve-box4">
            {field.props.map((prop) => (
              <div key={prop} className={styles[prop] !== undefined ? 'is-touched' : ''}>
                <ValueInput
                  value={styles[prop]}
                  placeholder={computed(prop)}
                  onChange={(value) => set(prop, value)}
                  label={prop}
                />
                <span>{prop.replace(/^(margin|padding)-/, '')}</span>
              </div>
            ))}
          </div>
        </Row>
      );
    }

    const value = styles[field.prop];
    const reset = () => set(field.prop, '');

    return (
      <Row key={field.prop} label={field.label} touched={value !== undefined} onReset={reset}>
        {field.kind === 'text' && (
          <ValueInput
            value={value}
            placeholder={computed(field.prop)}
            list={field.list}
            onChange={(next) => set(field.prop, next)}
            label={field.label}
          />
        )}
        {field.kind === 'select' && (
          <SelectInput
            value={value}
            placeholder={computed(field.prop)}
            options={field.options}
            onChange={(next) => set(field.prop, next)}
            label={field.label}
          />
        )}
        {field.kind === 'color' && (
          <ColorInput
            value={value}
            computed={computed(field.prop)}
            tokens={tokens}
            onChange={(next) => set(field.prop, next)}
            label={field.label}
          />
        )}
        {field.kind === 'segment' && (
          <Segmented value={value} options={field.options} onChange={(next) => set(field.prop, next)} />
        )}
      </Row>
    );
  };

  const extras = Object.entries(styles).filter(([prop]) => !KNOWN_PROPS.has(prop));

  return (
    <div className="ve-panel-body">
      {state !== 'normal' && (
        <p className="ve-note">
          Editing the <b>:{state}</b> look. Select this element in Browse mode and hover it, or just look at the preview:
          the editor shows it as if the element were {state === 'hover' ? 'hovered' : state === 'focus' ? 'focused' : 'pressed'}.
        </p>
      )}
      {device !== 'base' && (
        <p className="ve-note">
          Editing the <b>{device}</b> layout — these values apply at {device === 'tablet' ? '1023px' : '767px'} wide and
          below.
        </p>
      )}

      {SECTIONS.map((section) => {
        const used = section.fields.reduce(
          (count, field) =>
            count +
            (field.kind === 'box'
              ? field.props.filter((prop) => styles[prop] !== undefined).length
              : styles[field.prop] !== undefined
                ? 1
                : 0),
          0,
        );

        return (
          <Section key={section.id} title={section.title} count={used} defaultOpen={section.open}>
            {section.fields.map(renderField)}
          </Section>
        );
      })}

      <Section title="Other properties" count={extras.length}>
        {extras.map(([prop, value]) => (
          <Row key={prop} label={prop} touched onReset={() => set(prop, '')}>
            <ValueInput value={value} onChange={(next) => set(prop, next)} label={prop} />
          </Row>
        ))}
        <AddProperty onAdd={set} />
      </Section>
    </div>
  );
};

/** Any CSS property that has no dedicated control. */
const AddProperty = ({ onAdd }: { onAdd: (prop: string, value: string) => void }) => {
  const [prop, setProp] = useState('');
  const [value, setValue] = useState('');
  const ready = /^(--[\w-]+|[a-z-]+)$/i.test(prop.trim()) && value.trim() !== '';

  const add = () => {
    if (!ready) return;
    onAdd(prop.trim().toLowerCase(), value.trim());
    setProp('');
    setValue('');
  };

  return (
    <div className="ve-add">
      <input
        className="ve-input"
        placeholder="property (e.g. letter-spacing)"
        value={prop}
        spellCheck={false}
        onChange={(event) => setProp(event.target.value)}
      />
      <input
        className="ve-input"
        placeholder="value"
        value={value}
        spellCheck={false}
        onChange={(event) => setValue(event.target.value)}
        onKeyDown={(event) => event.key === 'Enter' && add()}
      />
      <button type="button" className="ve-btn" disabled={!ready} onClick={add}>
        Add
      </button>
    </div>
  );
};
