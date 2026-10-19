import { lazy, Suspense, useEffect, useRef, useState, type ReactNode } from 'react';
import { ChevronDown, ChevronRight, X } from 'lucide-react';
import { t } from '../i18n';

export const Section = ({ title, hint, children, open: initial = true, right }: { title: string; hint?: string; children: ReactNode; open?: boolean; right?: ReactNode }) => {
  const key = 've-sec:' + title;
  const [open, setOpen] = useState(() => {
    try {
      const v = localStorage.getItem(key);
      return v === null ? initial : v === '1';
    } catch {
      return initial;
    }
  });
  const toggle = () => {
    setOpen(!open);
    try {
      localStorage.setItem(key, open ? '0' : '1');
    } catch {
      /* private mode */
    }
  };
  return (
    <section className="ve-section">
      <header onClick={toggle}>
        <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          {open ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
          {title} {hint && <span className="hint">{hint}</span>}
        </span>
        <span onClick={(e) => e.stopPropagation()}>{right}</span>
      </header>
      {open && <div>{children}</div>}
    </section>
  );
};

export const Row = ({ label, set, children, title }: { label: string; set?: boolean; children: ReactNode; title?: string }) => (
  <div className={'ve-row' + (set ? ' set' : '')} title={title}>
    <label>{label}</label>
    <div style={{ minWidth: 0 }}>{children}</div>
  </div>
);

export const Seg = <T extends string>({ value, options, onChange }: { value: T; options: { value: T; label?: ReactNode; title?: string }[]; onChange: (v: T) => void }) => (
  <div className="ve-seg">
    {options.map((o) => (
      <button key={o.value} type="button" className={o.value === value ? 'on' : ''} title={o.title} onClick={() => onChange(o.value)}>
        {o.label ?? o.value}
      </button>
    ))}
  </div>
);

/** Text input that commits on every change but keeps its own state while typing. */
export const Field = ({ value, onChange, placeholder, className, list, mono, onBlur, type = 'text' }: { value: string; onChange: (v: string) => void; placeholder?: string; className?: string; list?: string; mono?: boolean; onBlur?: () => void; type?: string }) => {
  const [local, setLocal] = useState(value);
  const focused = useRef(false);
  useEffect(() => {
    if (!focused.current) setLocal(value);
  }, [value]);
  return (
    <input
      className={'ve-input' + (mono ? ' code' : '') + (className ? ' ' + className : '')}
      value={local}
      type={type}
      list={list}
      placeholder={placeholder}
      onFocus={() => (focused.current = true)}
      onBlur={() => {
        focused.current = false;
        onBlur?.();
      }}
      onChange={(e) => {
        setLocal(e.target.value);
        onChange(e.target.value);
      }}
    />
  );
};

export const Area = ({ value, onChange, placeholder, rows = 4, mono }: { value: string; onChange: (v: string) => void; placeholder?: string; rows?: number; mono?: boolean }) => {
  const [local, setLocal] = useState(value);
  const focused = useRef(false);
  useEffect(() => {
    if (!focused.current) setLocal(value);
  }, [value]);
  return (
    <textarea
      className={'ve-textarea' + (mono ? ' code' : '')}
      rows={rows}
      value={local}
      placeholder={placeholder}
      onFocus={() => (focused.current = true)}
      onBlur={() => (focused.current = false)}
      onChange={(e) => {
        setLocal(e.target.value);
        onChange(e.target.value);
      }}
    />
  );
};

export const Select = ({ value, onChange, options, placeholder }: { value: string; onChange: (v: string) => void; options: (string | { value: string; label: string })[]; placeholder?: string }) => (
  <select className="ve-select" value={value} onChange={(e) => onChange(e.target.value)}>
    {placeholder !== undefined && <option value="">{placeholder}</option>}
    {options.map((o) => {
      const v = typeof o === 'string' ? o : o.value;
      return (
        <option key={v} value={v}>
          {typeof o === 'string' ? o : o.label}
        </option>
      );
    })}
  </select>
);

const toHex = (c: string): string => {
  if (/^#[0-9a-f]{6}$/i.test(c)) return c;
  if (/^#[0-9a-f]{3}$/i.test(c)) return '#' + c.slice(1).split('').map((x) => x + x).join('');
  const m = /rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i.exec(c);
  if (m) return '#' + [m[1], m[2], m[3]].map((n) => Number(n).toString(16).padStart(2, '0')).join('');
  return '#000000';
};

export const ColorInput = ({ value, onChange, swatches = [] }: { value: string; onChange: (v: string) => void; swatches?: { name: string; value: string }[] }) => (
  <div style={{ display: 'grid', gap: 4 }}>
    <div className="ve-color">
      <label className="sw" title={value}>
        <i style={{ background: value || 'transparent' }} />
        <input type="color" value={toHex(value)} onChange={(e) => onChange(e.target.value)} />
      </label>
      <Field value={value} onChange={onChange} placeholder="#hex, rgb(), var(--…)" mono />
    </div>
    {swatches.length > 0 && (
      <div className="ve-swatches">
        {swatches.map((s) => (
          <button key={s.name} type="button" title={s.name} style={{ background: s.value }} onClick={() => onChange(`var(${s.name})`)} />
        ))}
      </div>
    )}
  </div>
);

/** Number + unit; also accepts free CSS (auto, clamp(), var()). Arrow keys step (Shift ×10, Alt ×0.1). */
export const UnitInput = ({ value, onChange, placeholder, units = ['px', '%', 'rem', 'em', 'vw', 'vh'] }: { value: string; onChange: (v: string) => void; placeholder?: string; units?: string[] }) => {
  const [local, setLocal] = useState(value);
  const focused = useRef(false);
  useEffect(() => {
    if (!focused.current) setLocal(value);
  }, [value]);
  const normalize = (v: string) => (/^-?\d*\.?\d+$/.test(v.trim()) && v.trim() !== '0' && units.includes('px') ? v.trim() + 'px' : v.trim());
  const commit = (v: string) => {
    setLocal(v);
    onChange(v);
  };
  const onKey = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') commit(normalize(local));
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
    const m = /^(-?\d*\.?\d+)([a-z%]*)$/i.exec(local.trim());
    if (!m) return;
    e.preventDefault();
    const step = e.shiftKey ? 10 : e.altKey ? 0.1 : 1;
    const n = Math.round((parseFloat(m[1]) + (e.key === 'ArrowUp' ? step : -step)) * 100) / 100;
    commit(n + (m[2] || (units.includes('px') ? 'px' : '')));
  };
  return (
    <div style={{ display: 'flex', gap: 4 }}>
      <input
        className="ve-input"
        value={local}
        placeholder={placeholder}
        onKeyDown={onKey}
        onFocus={() => (focused.current = true)}
        onBlur={() => {
          focused.current = false;
          const n = normalize(local);
          if (n !== local) commit(n);
        }}
        onChange={(e) => {
          setLocal(e.target.value);
          // Bare numbers are previewed as px while typing; the field shows them as typed.
          onChange(normalize(e.target.value));
        }}
      />
      {units.length > 0 && (
        <select
          className="ve-select"
          style={{ width: 54, padding: '0 2px' }}
          value={/([a-z%]+)$/i.exec(local)?.[1] ?? ''}
          onChange={(e) => {
            const n = /^(-?\d*\.?\d+)/.exec(local)?.[1];
            if (n) commit(n + e.target.value);
          }}
        >
          <option value="">–</option>
          {units.map((u) => (
            <option key={u} value={u}>
              {u}
            </option>
          ))}
        </select>
      )}
    </div>
  );
};

export const Modal = ({ title, children, onClose, footer, wide }: { title: ReactNode; children: ReactNode; onClose: () => void; footer?: ReactNode; wide?: boolean }) => {
  useEffect(() => {
    const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', k);
    return () => window.removeEventListener('keydown', k);
  }, [onClose]);
  return (
    <div className="ve-modal-bg" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={'ve-modal' + (wide ? ' wide' : '')} role="dialog" aria-modal="true">
        <header>
          <span>{title}</span>
          <button className="ve-icon-btn" onClick={onClose} aria-label={t('Close')}>
            <X size={16} />
          </button>
        </header>
        <div className="body">{children}</div>
        {footer && <footer>{footer}</footer>}
      </div>
    </div>
  );
};

export type MenuItem = { label: string; icon?: ReactNode; kbd?: string; onClick: () => void; danger?: boolean } | 'sep';

export const ContextMenu = ({ x, y, items, onClose }: { x: number; y: number; items: MenuItem[]; onClose: () => void }) => {
  useEffect(() => {
    const close = () => onClose();
    window.addEventListener('mousedown', close);
    window.addEventListener('blur', close);
    return () => {
      window.removeEventListener('mousedown', close);
      window.removeEventListener('blur', close);
    };
  }, [onClose]);
  const left = Math.min(x, window.innerWidth - 220);
  const top = Math.min(y, window.innerHeight - items.length * 30 - 20);
  return (
    <div className="ve-menu" style={{ left, top }} onMouseDown={(e) => e.stopPropagation()}>
      {items.map((it, i) =>
        it === 'sep' ? (
          <hr key={i} />
        ) : (
          <button
            key={i}
            style={it.danger ? { color: '#fca5a5' } : undefined}
            onClick={() => {
              onClose();
              it.onClick();
            }}
          >
            {it.icon}
            {it.label}
            {it.kbd && <kbd>{it.kbd}</kbd>}
          </button>
        ),
      )}
    </div>
  );
};

const CodeMirrorEditor = lazy(() => import('./CodeEditor'));

export const Code = ({ value, onChange, lang, height = 280 }: { value: string; onChange: (v: string) => void; lang: 'html' | 'css' | 'js' | 'jsx'; height?: number }) => (
  <Suspense fallback={<textarea className="ve-textarea code" style={{ height }} value={value} onChange={(e) => onChange(e.target.value)} />}>
    <CodeMirrorEditor value={value} onChange={onChange} lang={lang} height={height} />
  </Suspense>
);
