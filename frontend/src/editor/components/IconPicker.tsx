// Lucide icons inserted as inline SVG (no icon font or library needed on the site).
import { useMemo, useState } from 'react';
import { icons } from 'lucide-react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createElement } from 'react';
import { t } from '../i18n';

const names = Object.keys(icons);

export default function IconPicker({ onPick }: { onPick: (svg: string) => void }) {
  const [q, setQ] = useState('');
  const [size, setSize] = useState(32);
  const list = useMemo(() => names.filter((n) => n.toLowerCase().includes(q.toLowerCase())).slice(0, 240), [q]);
  return (
    <div style={{ display: 'grid', gap: 8 }}>
      <div className="ve-grid2">
        <input className="ve-input" placeholder={t('Search icons (plane, map, star…)')} value={q} onChange={(e) => setQ(e.target.value)} />
        <select className="ve-select" value={size} onChange={(e) => setSize(Number(e.target.value))}>
          {[16, 20, 24, 32, 48, 64].map((s) => (
            <option key={s} value={s}>
              {s}px
            </option>
          ))}
        </select>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6, 1fr)', gap: 4 }}>
        {list.map((n) => {
          const Icon = icons[n as keyof typeof icons];
          return (
            <button
              key={n}
              className="ve-icon-btn"
              title={n}
              style={{ width: '100%', height: 36 }}
              onClick={() => onPick(renderToStaticMarkup(createElement(Icon, { size, strokeWidth: 1.75, 'aria-hidden': true })).replace(' class="lucide', ' class="sve-svg lucide'))}
            >
              <Icon size={18} />
            </button>
          );
        })}
      </div>
    </div>
  );
}
