import { useId, type KeyboardEvent, type ReactNode } from 'react';

/* ---------- colour helpers ---------- */

/** `#rrggbb` for an `rgb()/rgba()/color(srgb …)` string, or null when it is not a plain colour. */
export const cssToHex = (value: string): string | null => {
  const text = value.trim();
  if (/^#[0-9a-f]{6}$/i.test(text)) return text.toLowerCase();
  if (/^#[0-9a-f]{3}$/i.test(text)) {
    return `#${text
      .slice(1)
      .split('')
      .map((c) => c + c)
      .join('')}`.toLowerCase();
  }

  const rgb = /^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i.exec(text);
  if (rgb) {
    return `#${[rgb[1], rgb[2], rgb[3]].map((n) => Number(n).toString(16).padStart(2, '0')).join('')}`;
  }

  const srgb = /^color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/i.exec(text);
  if (srgb) {
    return `#${[srgb[1], srgb[2], srgb[3]]
      .map((n) =>
        Math.round(Number(n) * 255)
          .toString(16)
          .padStart(2, '0'),
      )
      .join('')}`;
  }

  return null;
};

/* ---------- numeric stepping (ArrowUp / ArrowDown like browser dev tools) ---------- */

const step = (event: KeyboardEvent<HTMLInputElement>, current: string, onChange: (value: string) => void) => {
  if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
  const match = /^(-?\d*\.?\d+)(.*)$/.exec(current.trim());
  if (!match) return;

  event.preventDefault();
  const unit = match[2] ?? '';
  const size = event.shiftKey ? 10 : event.altKey ? 0.1 : 1;
  const next = Number(match[1]) + (event.key === 'ArrowUp' ? size : -size);
  onChange(`${Math.round(next * 100) / 100}${unit}`);
};

/* ---------- inputs ---------- */

type TextProps = {
  value: string | undefined;
  placeholder?: string;
  onChange: (value: string) => void;
  list?: string[];
  label?: string;
  className?: string;
};

export const ValueInput = ({ value, placeholder, onChange, list, label, className }: TextProps) => {
  const id = useId();
  return (
    <>
      <input
        className={`ve-input ${className ?? ''}`}
        value={value ?? ''}
        placeholder={placeholder}
        aria-label={label}
        list={list ? id : undefined}
        spellCheck={false}
        onChange={(event) => onChange(event.target.value)}
        onKeyDown={(event) => step(event, value || placeholder || '', onChange)}
      />
      {list && (
        <datalist id={id}>
          {list.map((option) => (
            <option key={option} value={option} />
          ))}
        </datalist>
      )}
    </>
  );
};

type ColorProps = {
  value: string | undefined;
  /** Computed value of the element (shown when nothing is set). */
  computed?: string;
  tokens?: { name: string; color: string }[];
  onChange: (value: string) => void;
  label?: string;
};

export const ColorInput = ({ value, computed, tokens, onChange, label }: ColorProps) => {
  const shown = value ?? computed ?? '';
  // var(--token) and other non-literal values cannot be shown by the picker: fall back to what the browser resolved.
  const hex = cssToHex(shown) ?? cssToHex(computed ?? '') ?? '#000000';
  return (
    <div className="ve-color">
      <div className="ve-color__row">
        <input
          type="color"
          className="ve-swatch"
          value={hex}
          aria-label={label ? `${label} picker` : 'Colour picker'}
          onChange={(event) => onChange(event.target.value)}
        />
        <ValueInput value={value} placeholder={computed} onChange={onChange} label={label} />
      </div>
      {tokens && tokens.length > 0 && (
        <div className="ve-tokens">
          {tokens.map((token) => (
            <button
              key={token.name}
              type="button"
              className="ve-token"
              style={{ background: token.color }}
              title={`var(${token.name})`}
              aria-label={`Use ${token.name}`}
              onClick={() => onChange(`var(${token.name})`)}
            />
          ))}
        </div>
      )}
    </div>
  );
};

type SegmentProps = {
  value: string | undefined;
  options: { value: string; label: ReactNode; title?: string }[];
  onChange: (value: string) => void;
};

export const Segmented = ({ value, options, onChange }: SegmentProps) => (
  <div className="ve-segment" role="group">
    {options.map((option) => (
      <button
        key={option.value}
        type="button"
        title={option.title ?? option.value}
        className={value === option.value ? 'is-on' : ''}
        onClick={() => onChange(value === option.value ? '' : option.value)}
      >
        {option.label}
      </button>
    ))}
  </div>
);

type SelectProps = {
  value: string | undefined;
  placeholder?: string;
  options: string[];
  onChange: (value: string) => void;
  label?: string;
};

export const SelectInput = ({ value, placeholder, options, onChange, label }: SelectProps) => (
  <select
    className={`ve-input ${value ? '' : 've-input--unset'}`}
    value={value ?? ''}
    aria-label={label}
    onChange={(event) => onChange(event.target.value)}
  >
    <option value="">{placeholder ? `${placeholder}` : '—'}</option>
    {options.map((option) => (
      <option key={option} value={option}>
        {option}
      </option>
    ))}
  </select>
);

/* ---------- layout ---------- */

export const Row = ({
  label,
  touched,
  onReset,
  children,
}: {
  label: string;
  touched?: boolean;
  onReset?: () => void;
  children: ReactNode;
}) => (
  <div className={`ve-row ${touched ? 'is-touched' : ''}`}>
    <label className="ve-row__label" title={label}>
      {label}
      {touched && onReset && (
        <button type="button" className="ve-reset" onClick={onReset} aria-label={`Reset ${label}`} title="Reset">
          ×
        </button>
      )}
    </label>
    <div className="ve-row__field">{children}</div>
  </div>
);

export const Section = ({
  title,
  count = 0,
  defaultOpen = false,
  children,
}: {
  title: string;
  count?: number;
  defaultOpen?: boolean;
  children: ReactNode;
}) => (
  <details className="ve-section" open={defaultOpen}>
    <summary>
      <span>{title}</span>
      {count > 0 && <span className="ve-badge">{count}</span>}
    </summary>
    <div className="ve-section__body">{children}</div>
  </details>
);
