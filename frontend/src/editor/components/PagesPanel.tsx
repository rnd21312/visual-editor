import { useCallback, useEffect, useState } from 'react';
import { Plus, FileText, LayoutTemplate, Menu as MenuIcon, MoreHorizontal, Trash2, Copy, Home, ExternalLink, PenLine, ArrowUp, ArrowDown, IndentIncrease, IndentDecrease, X } from 'lucide-react';
import { useStore, flushWorking } from '../store';
import { ContextMenu, Seg, Select, Modal, Field, Row, type MenuItem } from './ui';
import { ensureKit } from './AddPanel';
import { PRESETS } from '../presets';
import { ensureIds } from '../../shared/source';
import type { BuilderTemplate } from '../config';
import { t } from '../i18n';

type Item = { id: number; title: string; type: string; status: string; url: string; thumb: string; builder: string; role: string; modified: string };

const block = (id: string) => PRESETS.find((p) => p.id === id)?.html ?? '';

export const STARTERS: Record<string, { name: string; html: () => string }> = {
  blank: { name: 'Blank', html: () => '<section class="sve-section"><div class="sve-container sve-stack"><h1 class="sve-h1">{{ post.title }}</h1><p class="sve-lead">Start building…</p></div></section>' },
  landing: { name: 'Landing page', html: () => [block('s-hero-split'), block('s-logos'), block('s-features'), block('s-stats'), block('s-testimonials'), block('s-pricing'), block('s-faq'), block('s-cta')].join('\n') },
  about: { name: 'About', html: () => [block('s-hero'), block('s-stats'), block('s-features'), block('s-testimonials'), block('s-cta')].join('\n') },
  contact: { name: 'Contact', html: () => [block('s-contact'), block('s-faq')].join('\n') },
  shop: { name: 'Shop home', html: () => [block('s-hero-split'), block('s-products'), block('s-features'), block('s-newsletter')].join('\n') },
  content: { name: 'Keep current content', html: () => '<section class="sve-section"><div class="sve-container sve-narrow sve-stack"><h1 class="sve-h1">{{ post.title }}</h1><sve-content></sve-content></div></section>' },
};

export const TEMPLATE_STARTERS: Record<string, string> = {
  'single:post': '<article class="sve-section"><div class="sve-container sve-narrow sve-stack"><sve-breadcrumbs></sve-breadcrumbs><span class="sve-badge">{{ post.category.name }}</span><h1 class="sve-h1">{{ post.title }}</h1><p class="sve-text">{{ post.date }} · {{ post.author.name }} · {{ post.reading_time }} min</p>{{#if post.has_image}}<img class="sve-media" src="{{ post.image.large }}" alt="{{ post.image.alt }}">{{/if}}<div class="sve-text"><sve-content></sve-content></div></div></article><section class="sve-section sve-soft"><div class="sve-container sve-stack"><h2 class="sve-h2">Related</h2>' + '<div class="sve-grid" style="--cols:3"><sve-loop source="related" query=\'{"posts_per_page":3}\' as="item"><a class="sve-card" href="{{ item.url }}" style="text-decoration:none"><img src="{{ item.image.medium }}" alt=""><h3 class="sve-h3">{{ item.title }}</h3></a></sve-loop></div></div></section>',
  'single:product': '<section class="sve-section"><div class="sve-container sve-stack"><sve-breadcrumbs></sve-breadcrumbs><div class="sve-split" style="align-items:start"><img class="sve-media" src="{{ product.image.large }}" alt="{{ post.title }}"><div class="sve-stack"><h1 class="sve-h1">{{ post.title }}</h1><div class="sve-lead">{{{ product.price_html }}}</div><div class="sve-text">{{{ product.short_description }}}</div>{{{ product.add_to_cart }}}<ul class="sve-list">{{#each product.attributes as a}}<li>{{ a.name }}: {{ a.text }}</li>{{/each}}</ul></div></div><div class="sve-text"><sve-content></sve-content></div></div></section><section class="sve-section sve-soft"><div class="sve-container sve-stack"><h2 class="sve-h2">You may also like</h2><div class="sve-grid" style="--cols:4"><sve-loop source="related" query=\'{"posts_per_page":4}\' as="item"><a class="sve-card" href="{{ item.url }}" style="text-decoration:none"><img src="{{ item.image.medium }}" alt=""><h3 class="sve-h3">{{ item.title }}</h3><div>{{{ item.price_html }}}</div></a></sve-loop></div></div></section>',
  archive: '<section class="sve-section"><div class="sve-container sve-stack" style="gap:32px"><h1 class="sve-h1">{{ archive.title }}</h1><p class="sve-lead">{{ archive.description }}</p><div class="sve-grid" style="--cols:3"><sve-loop source="main" as="item"><article class="sve-card"><a href="{{ item.url }}"><img src="{{ item.image.medium_large }}" alt="" loading="lazy"></a><h3 class="sve-h3"><a href="{{ item.url }}" style="color:inherit;text-decoration:none">{{ item.title }}</a></h3><p class="sve-text">{{ item.excerpt | words:18 }}</p></article><sve-empty><p class="sve-text">Nothing here yet.</p></sve-empty></sve-loop></div><sve-pagination></sve-pagination></div></section>',
  header: block('s-header'),
  footer: block('s-footer'),
  '404': '<section class="sve-section"><div class="sve-container sve-narrow sve-stack sve-center" style="text-align:center"><span class="sve-eyebrow">404</span><h1 class="sve-h1">This page took a vacation</h1><p class="sve-lead">Try searching, or go back home.</p><sve-search placeholder="Search…"></sve-search><a class="sve-btn" href="{{ site.url }}">Home</a></div></section>',
  search: '<section class="sve-section"><div class="sve-container sve-stack"><h1 class="sve-h1">{{ archive.title }}</h1><sve-search></sve-search><div class="sve-stack"><sve-loop source="main" as="item"><a class="sve-card" href="{{ item.url }}" style="text-decoration:none"><h3 class="sve-h3">{{ item.title }}</h3><p class="sve-text">{{ item.excerpt | words:30 }}</p></a><sve-empty><p class="sve-text">No results.</p></sve-empty></sve-loop></div><sve-pagination></sve-pagination></div></section>',
};

export const PagesPanel = () => {
  const [tab, setTab] = useState<'content' | 'templates' | 'menus'>('content');
  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%', minHeight: 0 }}>
      <div className="ve-tabs">
        <button className={tab === 'content' ? 'on' : ''} onClick={() => setTab('content')}>
          <FileText size={13} /> {t('Content')}
        </button>
        <button className={tab === 'templates' ? 'on' : ''} onClick={() => setTab('templates')}>
          <LayoutTemplate size={13} /> {t('Templates')}
        </button>
        <button className={tab === 'menus' ? 'on' : ''} onClick={() => setTab('menus')}>
          <MenuIcon size={13} /> {t('Menus')}
        </button>
      </div>
      {tab === 'content' && <ContentList />}
      {tab === 'templates' && <TemplateList />}
      {tab === 'menus' && <Menus />}
    </div>
  );
};

const ContentList = () => {
  const { api, cfg, navigate, toast } = useStore.getState();
  const [type, setType] = useState('page');
  const [search, setSearch] = useState('');
  const [items, setItems] = useState<Item[]>([]);
  const [menu, setMenu] = useState<{ x: number; y: number; item: Item } | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    const id = window.setTimeout(() => void api.get<{ items: Item[] }>('content', { type, search, per_page: 60 }).then((r) => setItems(r.items)), 200);
    return () => window.clearTimeout(id);
  }, [type, search, api, tick]);

  const actions = (it: Item): MenuItem[] => [
    { label: t('Edit visually'), icon: <PenLine size={13} />, onClick: () => navigate(it.url) },
    ...(!it.builder
      ? [
          {
            label: t('Rebuild with the builder'),
            icon: <LayoutTemplate size={13} />,
            onClick: async () => {
              ensureKit();
              if (!window.confirm(t('Convert “{title}” to a builder layout? Its current content is kept inside the layout. Unsaved editor changes are published too.', { title: it.title }))) return;
              await useStore.getState().save();
              await api.put(`content/${it.id}`, { builder: { source: ensureIds(STARTERS.content.html()), mode: 'theme' } });
              toast(t('This page now uses a builder layout (its content is kept).'), 'success');
              navigate(it.url);
            },
          } as MenuItem,
        ]
      : []),
    { label: t('Duplicate'), icon: <Copy size={13} />, onClick: async () => { await api.post(`content/${it.id}/duplicate`); setTick((x) => x + 1); } },
    ...(it.type === 'page' ? [{ label: t('Set as home page'), icon: <Home size={13} />, onClick: async () => { await api.put(`content/${it.id}`, { front_page: true }); toast(t('Home page changed.'), 'success'); } } as MenuItem] : []),
    { label: t('Open in WordPress editor'), icon: <ExternalLink size={13} />, onClick: () => window.open(`${cfg.adminUrl}post.php?post=${it.id}&action=edit`, '_blank') },
    'sep',
    { label: it.status === 'publish' ? t('Unpublish (draft)') : t('Publish'), onClick: async () => { await api.put(`content/${it.id}`, { status: it.status === 'publish' ? 'draft' : 'publish' }); setTick((x) => x + 1); } },
    { label: t('Move to trash'), icon: <Trash2 size={13} />, danger: true, onClick: async () => { if (window.confirm(t('Move “{title}” to the trash?', { title: it.title }))) { await api.del(`content/${it.id}`); setTick((x) => x + 1); } } },
  ];

  return (
    <>
      <div className="ve-pad" style={{ display: 'grid', gap: 6, borderBottom: '1px solid var(--ve-line)' }}>
        <div style={{ display: 'flex', gap: 6 }}>
          <Select value={type} onChange={setType} options={Object.values(cfg.types).map((ty) => ({ value: ty.name, label: ty.label }))} />
          <button className="ve-btn primary" onClick={() => useStore.setState({ modal: { type: 'new-content', postType: type } })}>
            <Plus size={14} /> {t('New')}
          </button>
        </div>
        <input className="ve-input" placeholder={t('Search…')} value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      <div className="ve-scroll ve-pad">
        <div className="ve-list">
          {items.length === 0 && <div className="ve-faint">{t('Nothing found.')}</div>}
          {items.map((it) => (
            <div key={it.id} className="ve-item" onClick={() => navigate(it.url)} title={it.url}>
              <span className="ve-thumb" style={it.thumb ? { backgroundImage: `url(${it.thumb})` } : undefined}>
                {!it.thumb && (it.role === 'front' ? <Home size={14} /> : <FileText size={14} />)}
              </span>
              <span style={{ display: 'grid', minWidth: 0 }}>
                <span className="t">{it.title}</span>
                <span className="ve-faint">
                  {it.status !== 'publish' ? it.status + ' · ' : ''}
                  {it.builder ? t('builder') + ' (' + it.builder + ')' : t('theme content')}
                  {it.role === 'front' ? ' · ' + t('home') : ''}
                </span>
              </span>
              <button className="ve-icon-btn" style={{ marginInlineStart: 'auto' }} onClick={(e) => { e.stopPropagation(); setMenu({ x: e.clientX, y: e.clientY, item: it }); }}>
                <MoreHorizontal size={15} />
              </button>
            </div>
          ))}
        </div>
      </div>
      {menu && <ContextMenu x={menu.x} y={menu.y} items={actions(menu.item)} onClose={() => setMenu(null)} />}
    </>
  );
};

export const NewContentModal = ({ postType }: { postType?: string }) => {
  const { api, cfg, navigate, toast } = useStore.getState();
  const [type, setType] = useState(postType ?? 'page');
  const [title, setTitle] = useState('');
  const [mode, setMode] = useState<'theme' | 'canvas' | 'blank'>('canvas');
  const [starter, setStarter] = useState('landing');
  const [busy, setBusy] = useState(false);
  const close = () => useStore.setState({ modal: null });
  const create = async () => {
    setBusy(true);
    try {
      ensureKit();
      // The kit CSS must reach the preview before the page renders.
      await flushWorking();
      const r = await api.post<{ id: number; url: string }>('content', { type, title: title || t('Untitled'), status: 'draft', builder: { source: ensureIds(STARTERS[starter].html()), mode } });
      toast(t('Created as a draft. Publish it from the Pages panel when ready.'), 'success');
      close();
      navigate(r.url.includes('?') ? r.url : r.url);
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal title={t('New content')} onClose={close} footer={<><button className="ve-btn" onClick={close}>{t('Cancel')}</button><button className="ve-btn primary" disabled={busy} onClick={() => void create()}>{t('Create')}</button></>}>
      <Row label={t('Type')}>
        <Select value={type} onChange={setType} options={Object.values(cfg.types).filter((x) => x.canCreate).map((x) => ({ value: x.name, label: x.singular }))} />
      </Row>
      <Row label={t('Title')}>
        <Field value={title} onChange={setTitle} placeholder={t('e.g. Phuket tours')} />
      </Row>
      <Row label={t('Layout')}>
        <Seg value={mode} onChange={setMode} options={[{ value: 'theme', label: t('Inside theme') }, { value: 'canvas', label: t('Full page') }, { value: 'blank', label: t('Blank canvas') }]} />
      </Row>
      <div className="ve-faint">{mode === 'theme' ? t('Keeps the theme header and footer.') : mode === 'canvas' ? t('Uses the builder header/footer (Templates → Header / Footer).') : t('Nothing but your design (landing pages).')}</div>
      <Row label={t('Start from')}>
        <Select value={starter} onChange={setStarter} options={Object.entries(STARTERS).filter(([k]) => k !== 'content').map(([k, v]) => ({ value: k, label: t(v.name) }))} />
      </Row>
    </Modal>
  );
};

const TemplateList = () => {
  const { api, cfg, navigate, toast } = useStore.getState();
  const [list, setList] = useState<BuilderTemplate[]>(cfg.builder);
  const load = useCallback(() => void api.get<{ builder: BuilderTemplate[] }>('templates').then((r) => setList(r.builder)), [api]);
  useEffect(load, [load]);
  const open = (b: BuilderTemplate) => {
    const sample = cfg.templates.find((x) => x.key === b.target || (b.target.endsWith('*') && x.key.startsWith(b.target.slice(0, -1))))?.url || cfg.homeUrl;
    useStore.setState({ tplPreview: b.id });
    navigate(sample);
  };
  return (
    <>
      <div className="ve-pad" style={{ borderBottom: '1px solid var(--ve-line)' }}>
        <button className="ve-btn primary block" onClick={() => useStore.setState({ modal: { type: 'new-template' } })}>
          <Plus size={14} /> {t('New template')}
        </button>
        <p className="ve-faint">{t('A template designs every page of one kind at once: all products, all posts, a category page, search, 404, or the site header/footer.')}</p>
      </div>
      <div className="ve-scroll ve-pad">
        <div className="ve-list">
          {list.length === 0 && <div className="ve-faint">{t('No builder templates yet.')}</div>}
          {list.map((b) => (
            <div key={b.id} className="ve-item" onClick={() => open(b)}>
              <span className="ve-thumb">
                <LayoutTemplate size={14} />
              </span>
              <span style={{ display: 'grid', minWidth: 0 }}>
                <span className="t">{b.title}</span>
                <span className="ve-faint">
                  {b.target} · {b.mode} {b.active ? '' : '· ' + t('inactive')}
                </span>
              </span>
              <label className="ve-check" style={{ marginInlineStart: 'auto' }} onClick={(e) => e.stopPropagation()} title={t('Active')}>
                <input type="checkbox" checked={b.active} onChange={async (e) => { await api.put(`content/${b.id}`, { status: e.target.checked ? 'publish' : 'draft' }); load(); }} />
              </label>
              <button className="ve-icon-btn" onClick={async (e) => { e.stopPropagation(); if (window.confirm(t('Delete template “{title}”?', { title: b.title }))) { await api.del(`content/${b.id}?force=1`); load(); toast(t('Deleted.')); } }}>
                <Trash2 size={13} />
              </button>
            </div>
          ))}
        </div>
      </div>
    </>
  );
};

export const NewTemplateModal = () => {
  const { api, cfg, navigate, toast } = useStore.getState();
  const targets = [
    ...cfg.templates.map((x) => ({ value: x.key, label: x.label })),
    { value: 'single:*', label: t('All single items') },
    { value: 'archive:*', label: t('All archives') },
    { value: 'header', label: t('Site header') },
    { value: 'footer', label: t('Site footer') },
  ];
  const [target, setTarget] = useState(cfg.woocommerce ? 'single:product' : 'single:post');
  const [title, setTitle] = useState('');
  const [mode, setMode] = useState<'theme' | 'canvas'>('theme');
  const [replace, setReplace] = useState('');
  const close = () => useStore.setState({ modal: null });
  const starter = TEMPLATE_STARTERS[target] ?? (target.startsWith('archive') || target.startsWith('tax:') || target === 'blog-index' ? TEMPLATE_STARTERS.archive : target.startsWith('single') ? TEMPLATE_STARTERS['single:post'] : STARTERS.blank.html());
  const create = async () => {
    ensureKit();
    await flushWorking();
    const r = await api.post<{ id: number }>('content', {
      type: 'sve_template',
      title: title || targets.find((x) => x.value === target)?.label,
      status: 'publish',
      template_target: target,
      builder: { source: ensureIds(starter), mode },
      ...(replace ? { replace } : {}),
    });
    toast(t('Template created and active.'), 'success');
    close();
    const sample = cfg.templates.find((x) => x.key === target || (target.endsWith('*') && x.key.startsWith(target.slice(0, -1))))?.url || cfg.homeUrl;
    useStore.setState({ tplPreview: r.id });
    navigate(sample);
  };
  const part = target === 'header' || target === 'footer';
  return (
    <Modal title={t('New template')} onClose={close} footer={<><button className="ve-btn" onClick={close}>{t('Cancel')}</button><button className="ve-btn primary" onClick={() => void create()}>{t('Create')}</button></>}>
      <Row label={t('For')}>
        <Select value={target} onChange={setTarget} options={targets} />
      </Row>
      <Row label={t('Name')}>
        <Field value={title} onChange={setTitle} placeholder={targets.find((x) => x.value === target)?.label} />
      </Row>
      {!part && (
        <Row label={t('Layout')}>
          <Seg value={mode} onChange={setMode} options={[{ value: 'theme', label: t('Inside theme') }, { value: 'canvas', label: t('Full page') }]} />
        </Row>
      )}
      {part && (
        <Row label={t('Replace theme')}>
          <Field value={replace} onChange={setReplace} placeholder={target === 'header' ? 'header.wp-block-template-part, #masthead' : 'footer.wp-block-template-part, #colophon'} mono />
        </Row>
      )}
      <div className="ve-faint">{part ? t('Used on full-page builder pages. To also replace the theme’s own header/footer, enter its selector.') : t('Starts with a ready layout using live data ({{ post.title }}, prices, related items…).')}</div>
    </Modal>
  );
};

/* ------------------------------------------------------------------ menus */

type MenuNode = { title: string; url: string; page_id?: number; children: MenuNode[] };
type MenuInfo = { id: number; name: string; locations: string[]; items: { id: number; title: string; url: string; parent: number; object: string; object_id: number }[] };

const toTree = (items: MenuInfo['items']): MenuNode[] => {
  const by: Record<number, MenuNode[]> = {};
  const nodes = new Map<number, MenuNode>();
  items.forEach((i) => nodes.set(i.id, { title: i.title, url: i.url, page_id: i.object === 'page' ? i.object_id : undefined, children: [] }));
  items.forEach((i) => (by[i.parent] ??= []).push(nodes.get(i.id)!));
  items.forEach((i) => (nodes.get(i.id)!.children = by[i.id] ?? []));
  return by[0] ?? [];
};

const Menus = () => {
  const { api, cfg, toast } = useStore.getState();
  const [menus, setMenus] = useState<MenuInfo[]>([]);
  const [edit, setEdit] = useState<{ id?: number; name: string; location: string; items: MenuNode[] } | null>(null);
  const load = useCallback(() => void api.get<{ menus: MenuInfo[] }>('menus').then((r) => setMenus(r.menus)), [api]);
  useEffect(load, [load]);

  if (edit) {
    // Flat list with depth (max 2 levels) for simple editing.
    const flat: { node: MenuNode; depth: number }[] = [];
    edit.items.forEach((n) => {
      flat.push({ node: n, depth: 0 });
      n.children.forEach((c) => flat.push({ node: c, depth: 1 }));
    });
    const rebuild = (list: { node: MenuNode; depth: number }[]) => {
      const out: MenuNode[] = [];
      list.forEach(({ node, depth }) => {
        const copy = { ...node, children: [] as MenuNode[] };
        if (depth === 1 && out.length) out[out.length - 1].children.push(copy);
        else out.push(copy);
      });
      setEdit({ ...edit, items: out });
    };
    const patch = (i: number, p: Partial<MenuNode>) => rebuild(flat.map((f, j) => (j === i ? { ...f, node: { ...f.node, ...p } } : f)));
    return (
      <div className="ve-scroll ve-pad" style={{ display: 'grid', gap: 8, alignContent: 'start' }}>
        <Field value={edit.name} onChange={(name) => setEdit({ ...edit, name })} placeholder={t('Menu name')} />
        <Select value={edit.location} onChange={(location) => setEdit({ ...edit, location })} placeholder={t('Theme location (optional)')} options={Object.entries(cfg.menuLocations).map(([k, v]) => ({ value: k, label: v }))} />
        {flat.map((f, i) => (
          <div key={i} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: 4, paddingInlineStart: f.depth * 16 }}>
            <input className="ve-input" value={f.node.title} onChange={(e) => patch(i, { title: e.target.value })} />
            <input className="ve-input code" value={f.node.url} onChange={(e) => patch(i, { url: e.target.value, page_id: undefined })} />
            <span style={{ display: 'flex' }}>
              <button className="ve-icon-btn" onClick={() => i > 0 && rebuild(flat.map((x, j) => (j === i - 1 ? flat[i] : j === i ? flat[i - 1] : x)))}><ArrowUp size={12} /></button>
              <button className="ve-icon-btn" onClick={() => i < flat.length - 1 && rebuild(flat.map((x, j) => (j === i + 1 ? flat[i] : j === i ? flat[i + 1] : x)))}><ArrowDown size={12} /></button>
              <button className="ve-icon-btn" onClick={() => rebuild(flat.map((x, j) => (j === i ? { ...x, depth: x.depth ? 0 : 1 } : x)))}>{f.depth ? <IndentDecrease size={12} /> : <IndentIncrease size={12} />}</button>
              <button className="ve-icon-btn" onClick={() => rebuild(flat.filter((_, j) => j !== i))}><X size={12} /></button>
            </span>
          </div>
        ))}
        <button className="ve-btn small" onClick={() => setEdit({ ...edit, items: [...edit.items, { title: t('New link'), url: '/', children: [] }] })}>
          <Plus size={13} /> {t('Add link')}
        </button>
        <div style={{ display: 'flex', gap: 6 }}>
          <button className="ve-btn" onClick={() => setEdit(null)}>{t('Cancel')}</button>
          <button
            className="ve-btn primary"
            onClick={async () => {
              const items = edit.items.map((n) => ({ title: n.title, ...(n.page_id ? { page_id: n.page_id } : { url: n.url }), children: n.children.map((c) => ({ title: c.title, ...(c.page_id ? { page_id: c.page_id } : { url: c.url }) })) }));
              await api.post('menus', { id: edit.id, name: edit.name, location: edit.location, items });
              toast(t('Menu saved.'), 'success');
              setEdit(null);
              load();
              useStore.getState().reload();
            }}
          >
            {t('Save menu')}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="ve-scroll ve-pad">
      <button className="ve-btn primary block" onClick={() => setEdit({ name: t('Main menu'), location: Object.keys(cfg.menuLocations)[0] ?? '', items: [{ title: t('Home'), url: cfg.homeUrl, children: [] }] })}>
        <Plus size={14} /> {t('New menu')}
      </button>
      <div className="ve-list" style={{ marginTop: 10 }}>
        {menus.map((m) => (
          <div key={m.id} className="ve-item" onClick={() => setEdit({ id: m.id, name: m.name, location: m.locations[0] ?? '', items: toTree(m.items) })}>
            <MenuIcon size={14} />
            <span className="t">{m.name}</span>
            <span className="meta">{m.locations.join(', ') || t('not placed')} · {m.items.length}</span>
          </div>
        ))}
      </div>
    </div>
  );
};
