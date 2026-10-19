import { useEffect, useRef, useState } from 'react';
import { Monitor, Tablet, Smartphone, Undo2, Redo2, MousePointer2, Hand, ExternalLink, Settings, History, Bot, ChevronDown, Search, FileText, LayoutTemplate, LogOut, Keyboard } from 'lucide-react';
import { useStore } from '../store';
import { Seg } from './ui';
import { t } from '../i18n';
import type { BuilderTemplate } from '../config';

type Item = { id: number; title: string; type: string; status: string; url: string; thumb: string; builder: string; role: string };

const PagePicker = ({ onClose }: { onClose: () => void }) => {
  const { api, cfg, navigate } = useStore.getState();
  const [tab, setTab] = useState<'content' | 'templates'>('content');
  const [type, setType] = useState('');
  const [search, setSearch] = useState('');
  const [items, setItems] = useState<Item[]>([]);
  const [builder, setBuilder] = useState<BuilderTemplate[]>(cfg.builder);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const id = window.setTimeout(() => {
      void api.get<{ items: Item[] }>('content', { search, type, per_page: 40 }).then((r) => setItems(r.items));
    }, 200);
    return () => window.clearTimeout(id);
  }, [search, type, api]);

  useEffect(() => {
    void api.get<{ builder: BuilderTemplate[] }>('templates').then((r) => setBuilder(r.builder));
    const close = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && onClose();
    window.addEventListener('mousedown', close);
    return () => window.removeEventListener('mousedown', close);
  }, [api, onClose]);

  const open = (url: string, tpl: number | null = null) => {
    useStore.setState({ tplPreview: tpl });
    navigate(url);
    onClose();
  };

  return (
    <div className="ve-pop" ref={ref}>
      <div className="ve-tabs">
        <button className={tab === 'content' ? 'on' : ''} onClick={() => setTab('content')}>
          <FileText size={13} /> {t('Pages & content')}
        </button>
        <button className={tab === 'templates' ? 'on' : ''} onClick={() => setTab('templates')}>
          <LayoutTemplate size={13} /> {t('Templates')}
        </button>
      </div>
      {tab === 'content' ? (
        <>
          <div className="ve-pad" style={{ display: 'grid', gridTemplateColumns: '1fr 140px', gap: 6 }}>
            <div style={{ position: 'relative' }}>
              <input className="ve-input" autoFocus placeholder={t('Search pages, posts, products…')} value={search} onChange={(e) => setSearch(e.target.value)} />
            </div>
            <select className="ve-select" value={type} onChange={(e) => setType(e.target.value)}>
              <option value="">{t('All types')}</option>
              {Object.values(cfg.types).map((ty) => (
                <option key={ty.name} value={ty.name}>
                  {ty.label}
                </option>
              ))}
            </select>
          </div>
          <div className="ve-scroll ve-pad" style={{ paddingTop: 0 }}>
            <div className="ve-list">
              <div className="ve-item" onClick={() => open(cfg.homeUrl)}>
                <span className="ve-thumb">🏠</span>
                <span className="t">{t('Home page')}</span>
              </div>
              {items.map((it) => (
                <div key={it.id} className="ve-item" onClick={() => open(it.url)} title={it.url}>
                  <span className="ve-thumb" style={it.thumb ? { backgroundImage: `url(${it.thumb})` } : undefined}>
                    {!it.thumb && <FileText size={14} />}
                  </span>
                  <span className="t">{it.title}</span>
                  <span className="meta">
                    {it.builder && <span className="ve-chip ai">{t('builder')}</span>} {cfg.types[it.type]?.singular ?? it.type}
                    {it.status !== 'publish' && ' · ' + it.status}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </>
      ) : (
        <div className="ve-scroll ve-pad">
          <div className="ve-h">{t('Builder templates')}</div>
          <div className="ve-list">
            {builder.length === 0 && <div className="ve-faint">{t('None yet — create one in the Pages panel.')}</div>}
            {builder.map((b) => {
              const sample = cfg.templates.find((x) => x.key === b.target || (b.target === 'single:*' && x.key.startsWith('single:')) || (b.target === 'archive:*' && x.key.startsWith('archive:')))?.url || cfg.homeUrl;
              return (
                <div key={b.id} className="ve-item" onClick={() => open(sample, b.id)}>
                  <span className="ve-thumb">
                    <LayoutTemplate size={14} />
                  </span>
                  <span className="t">{b.title}</span>
                  <span className="meta">
                    {b.target} {!b.active && '· ' + t('inactive')}
                  </span>
                </div>
              );
            })}
          </div>
          <div className="ve-h" style={{ marginTop: 12 }}>
            {t('Theme templates')} <span className="ve-faint">— {t('style once, applies to every item')}</span>
          </div>
          <div className="ve-list">
            {cfg.templates
              .filter((x) => x.url)
              .map((x) => (
                <div key={x.key} className="ve-item" onClick={() => open(x.url)}>
                  <span className="ve-thumb" style={x.thumb ? { backgroundImage: `url(${x.thumb})` } : undefined} />
                  <span className="t">{x.label}</span>
                  <span className="meta">{x.count ? x.count : ''}</span>
                </div>
              ))}
          </div>
        </div>
      )}
    </div>
  );
};

export const TopBar = () => {
  const s = useStore();
  const [picker, setPicker] = useState(false);
  const dirty = s.dirty();
  const title = s.ctx?.title?.split(/\s[–—|-]\s/)[0] || s.url;

  const status =
    s.saving === 'saving' ? t('Saving…') : s.saving === 'saved' ? t('Saved') : s.saving === 'error' ? t('Not saved') : dirty ? t('Unsaved changes') : '';

  return (
    <div className="ve-top">
      <div className="ve-brand" title={'Visual Editor ' + s.cfg.version}>
        <i /> <span>{s.cfg.siteName}</span>
      </div>
      <div style={{ position: 'relative', minWidth: 0 }}>
        <div className="ve-page-pick" onClick={() => setPicker(!picker)}>
          <Search size={14} />
          <span>{s.tplPreview ? '⧉ ' : ''}{title}</span>
          {s.ctx && <small>{s.ctx.template.label}</small>}
          <ChevronDown size={14} />
        </div>
        {picker && <PagePicker onClose={() => setPicker(false)} />}
      </div>
      <div className="grow" />
      <Seg
        value={s.device}
        onChange={(device) => useStore.setState({ device })}
        options={[
          { value: 'desktop', label: <Monitor size={15} />, title: t('Desktop') },
          { value: 'tablet', label: <Tablet size={15} />, title: t('Tablet') + ` (≤${s.doc.settings.breakpoints.tablet}px)` },
          { value: 'mobile', label: <Smartphone size={15} />, title: t('Mobile') + ` (≤${s.doc.settings.breakpoints.mobile}px)` },
        ]}
      />
      <Seg
        value={s.interact ? 'interact' : 'design'}
        onChange={(v) => useStore.setState({ interact: v === 'interact', selected: v === 'interact' ? null : s.selected })}
        options={[
          { value: 'design', label: <MousePointer2 size={15} />, title: t('Design: click selects elements') },
          { value: 'interact', label: <Hand size={15} />, title: t('Interact: use the page like a visitor (links, menus, sliders)') },
        ]}
      />
      <button className="ve-icon-btn" title={t('Undo') + ' (Ctrl+Z)'} disabled={!s.past.length} onClick={s.undo}>
        <Undo2 size={16} />
      </button>
      <button className="ve-icon-btn" title={t('Redo') + ' (Ctrl+Shift+Z)'} disabled={!s.future.length} onClick={s.redo}>
        <Redo2 size={16} />
      </button>
      <div className="grow" />
      <span className={'ve-status' + (s.saving === 'error' ? ' error' : '')}>{status}</span>
      <button className={'ve-icon-btn' + (s.left === 'ai' ? ' on' : '')} title={t('AI collaboration')} onClick={() => useStore.setState({ left: 'ai' })}>
        <Bot size={16} />
        {s.draft > 0 && <span className="ve-badge">{s.draft}</span>}
      </button>
      <button className="ve-icon-btn" title={t('History')} onClick={() => useStore.setState({ modal: { type: 'revisions' } })}>
        <History size={16} />
      </button>
      <button className="ve-icon-btn" title={t('Settings')} onClick={() => useStore.setState({ modal: { type: 'settings' } })}>
        <Settings size={16} />
      </button>
      <button className="ve-icon-btn" title={t('Keyboard shortcuts')} onClick={() => useStore.setState({ modal: { type: 'shortcuts' } })}>
        <Keyboard size={16} />
      </button>
      <a className="ve-icon-btn" title={t('Open this page')} href={s.url} target="_blank" rel="noreferrer">
        <ExternalLink size={16} />
      </a>
      {dirty && (
        <button className="ve-btn ghost" onClick={() => window.confirm(t('Throw away all unsaved changes?')) && void s.discard()}>
          {t('Discard')}
        </button>
      )}
      <button className="ve-btn primary" disabled={!dirty || s.saving === 'saving'} onClick={() => void s.save()}>
        {t('Publish')}
      </button>
      <a className="ve-icon-btn" title={t('Back to the dashboard')} href={s.cfg.adminUrl}>
        <LogOut size={16} />
      </a>
    </div>
  );
};
