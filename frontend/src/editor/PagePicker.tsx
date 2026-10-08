import { useEffect, useMemo, useRef, useState } from 'react';
import type { Api } from '@/admin/lib/api';
import type { EditorConfig } from './types';

type Item = {
  id: number;
  title: string;
  url: string;
  type: string;
  typeLabel: string;
  thumb: string;
  modified: string;
  status: string;
  role: '' | 'front' | 'blog';
};

type Result = { items: Item[]; total: number; pages: number; types: Record<string, string> };

type Props = {
  api: Api;
  config: EditorConfig;
  currentUrl: string;
  onPick: (url: string) => void;
  onClose: () => void;
};

const TEMPLATES = '__templates__';

const path = (url: string): string => {
  try {
    const parsed = new URL(url);
    return parsed.pathname + parsed.search;
  } catch {
    return url;
  }
};

const hue = (text: string): number => Array.from(text).reduce((sum, char) => (sum * 31 + char.charCodeAt(0)) % 360, 7);

/** A picture for the card: the featured image, or a coloured tile with the page's initial. */
const Thumb = ({ src, title }: { src: string; title: string }) =>
  src ? (
    <img className="ve-card__img" src={src} alt="" loading="lazy" />
  ) : (
    <span className="ve-card__img ve-card__img--blank" style={{ background: `linear-gradient(135deg, hsl(${hue(title)} 45% 32%), hsl(${(hue(title) + 40) % 360} 50% 22%))` }}>
      {title.trim().charAt(0).toUpperCase() || '·'}
    </span>
  );

/**
 * Pop-up for choosing what to edit: search, filter by kind, picture cards for pages, posts and products,
 * and the theme templates (edit once, applies to every product / post).
 */
export const PagePicker = ({ api, config, currentUrl, onPick, onClose }: Props) => {
  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const [items, setItems] = useState<Item[]>([]);
  const [total, setTotal] = useState(0);
  const [pages, setPages] = useState(1);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const searchRef = useRef<HTMLInputElement>(null);
  const templatesOnly = type === TEMPLATES;

  useEffect(() => searchRef.current?.focus(), []);

  useEffect(() => {
    const key = (event: KeyboardEvent) => event.key === 'Escape' && onClose();
    window.addEventListener('keydown', key);
    return () => window.removeEventListener('keydown', key);
  }, [onClose]);

  // Search (debounced) and filter changes start again from page 1.
  useEffect(() => {
    if (templatesOnly) return;
    setLoading(true);
    let stale = false; // a slower, older answer must not replace a newer one
    const timer = window.setTimeout(() => {
      api
        .get<Result>(`admin/pages?search=${encodeURIComponent(search)}&type=${encodeURIComponent(type)}&page=1`)
        .then((result) => {
          if (stale) return;
          setItems(result.items);
          setTotal(result.total);
          setPages(result.pages);
          setPage(1);
          setError('');
        })
        .catch(() => !stale && setError('Could not load the pages.'))
        .finally(() => !stale && setLoading(false));
    }, search ? 250 : 0);
    return () => {
      stale = true;
      window.clearTimeout(timer);
    };
  }, [api, search, type, templatesOnly]);

  const more = () => {
    setLoading(true);
    api
      .get<Result>(`admin/pages?search=${encodeURIComponent(search)}&type=${encodeURIComponent(type)}&page=${page + 1}`)
      .then((result) => {
        setItems((current) => [...current, ...result.items]);
        setPage(page + 1);
      })
      .catch(() => setError('Could not load more.'))
      .finally(() => setLoading(false));
  };

  const needle = search.trim().toLowerCase();
  const templates = useMemo(
    () => config.templates.filter((entry) => needle === '' || entry.label.toLowerCase().includes(needle)),
    [config.templates, needle],
  );
  const showTemplates = templatesOnly || (type === '' && needle === '');

  const same = (url: string) => url.replace(/\/$/, '') === currentUrl.replace(/\/$/, '').split('?')[0];

  return (
    <div className="ve-modal" role="presentation" onPointerDown={(event) => event.target === event.currentTarget && onClose()}>
      <div className="ve-modal__box ve-picker" role="dialog" aria-modal="true" aria-label="Choose a page to edit">
        <header className="ve-modal__head">
          <h2>Choose what to edit</h2>
          <button type="button" className="ve-icon-btn" aria-label="Close" onClick={onClose}>
            ×
          </button>
        </header>

        <div className="ve-picker__bar">
          <input
            ref={searchRef}
            className="ve-input ve-picker__search"
            placeholder="Search pages, posts, products…"
            value={search}
            spellCheck={false}
            onChange={(event) => setSearch(event.target.value)}
            aria-label="Search"
          />
          <div className="ve-chips" role="group" aria-label="Filter by kind">
            <button type="button" className={type === '' ? 'is-on' : ''} onClick={() => setType('')}>
              All
            </button>
            {Object.entries(config.pageTypes).map(([slug, label]) => (
              <button key={slug} type="button" className={type === slug ? 'is-on' : ''} onClick={() => setType(slug)}>
                {label}
              </button>
            ))}
            <button type="button" className={templatesOnly ? 'is-on' : ''} onClick={() => setType(TEMPLATES)}>
              Templates
            </button>
          </div>
        </div>

        <div className="ve-picker__body">
          {showTemplates && templates.length > 0 && (
            <section>
              <h3>
                Templates <small>— one edit applies to every item</small>
              </h3>
              <div className="ve-cards">
                {templates.map((entry) => (
                  <button key={entry.key} type="button" className={`ve-card ${same(entry.url) ? 'is-current' : ''}`} onClick={() => onPick(entry.url)}>
                    <Thumb src={entry.thumb} title={entry.label} />
                    <span className="ve-card__title">{entry.label}</span>
                    <span className="ve-card__meta">
                      <span className="ve-tagbadge ve-tagbadge--tpl">Template</span>
                      {entry.count > 0 && <span>{entry.count} items</span>}
                    </span>
                  </button>
                ))}
              </div>
            </section>
          )}

          {!templatesOnly && (
            <section>
              {showTemplates && templates.length > 0 && <h3>Pages &amp; content</h3>}
              <div className="ve-cards">
                {items.map((item) => (
                  <button key={item.id} type="button" className={`ve-card ${same(item.url) ? 'is-current' : ''}`} onClick={() => onPick(item.url)} title={item.url}>
                    <Thumb src={item.thumb} title={item.title} />
                    <span className="ve-card__title">{item.title}</span>
                    <span className="ve-card__meta">
                      <span className="ve-tagbadge">{item.typeLabel}</span>
                      {item.role === 'front' && <span className="ve-tagbadge ve-tagbadge--home">Home</span>}
                      {item.role === 'blog' && <span className="ve-tagbadge ve-tagbadge--home">Posts page</span>}
                      {item.status !== 'publish' && <span className="ve-tagbadge ve-tagbadge--warn">{item.status}</span>}
                    </span>
                    <span className="ve-card__path">{path(item.url)}</span>
                  </button>
                ))}
              </div>
              {!loading && items.length === 0 && !error && <p className="ve-note">Nothing matches “{search}”.</p>}
              {error && <p className="ve-hint ve-hint--warn">{error}</p>}
              {page < pages && (
                <button type="button" className="ve-btn ve-picker__more" disabled={loading} onClick={more}>
                  {loading ? 'Loading…' : `Show more (${total - items.length} left)`}
                </button>
              )}
            </section>
          )}
          {loading && items.length === 0 && !templatesOnly && <p className="ve-note">Loading…</p>}
        </div>

        <footer className="ve-modal__foot">
          <a href={config.homeUrl} onClick={(event) => (event.preventDefault(), onPick(config.homeUrl))}>
            Go to the home page
          </a>
          <span>Esc to close</span>
        </footer>
      </div>
    </div>
  );
};
