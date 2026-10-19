import { useEffect } from 'react';
import { Layers as LayersIcon, PlusSquare, FileText, Component as ComponentIcon, Palette, Search, Bot } from 'lucide-react';
import { useStore, type LeftTab } from './store';
import { TopBar } from './components/TopBar';
import { Canvas } from './components/Canvas';
import { Inspector } from './components/Inspector';
import { Layers } from './components/Layers';
import { AddPanel } from './components/AddPanel';
import { PagesPanel } from './components/PagesPanel';
import { ComponentsPanel } from './components/ComponentsPanel';
import { DesignPanel } from './components/DesignPanel';
import { SeoPanel } from './components/SeoPanel';
import { AiPanel } from './components/AiPanel';
import { Modals } from './components/Modals';
import { duplicateSelected, moveSibling, removeSelected, selectParent, insertHtml } from './actions';
import { outerOf, stripIds } from '../shared/source';
import { sourceOf } from './actions';
import { t } from './i18n';

const TABS: { key: LeftTab; icon: JSX.Element; label: string }[] = [
  { key: 'layers', icon: <LayersIcon size={14} />, label: 'Layers' },
  { key: 'add', icon: <PlusSquare size={14} />, label: 'Add' },
  { key: 'pages', icon: <FileText size={14} />, label: 'Pages' },
  { key: 'components', icon: <ComponentIcon size={14} />, label: 'Components' },
  { key: 'design', icon: <Palette size={14} />, label: 'Design' },
  { key: 'seo', icon: <Search size={14} />, label: 'SEO' },
  { key: 'ai', icon: <Bot size={14} />, label: 'AI' },
];

let clipboard = '';

const typing = (e: KeyboardEvent) => {
  const el = e.target as HTMLElement | null;
  return !!el && !!el.tagName && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || !!el.closest?.('.cm-editor'));
};

export const App = () => {
  const left = useStore((s) => s.left);
  const interact = useStore((s) => s.interact);
  const toasts = useStore((s) => s.toasts);
  const remoteRev = useStore((s) => s.remoteRev);
  const base = useStore((s) => s.base);
  const selected = useStore((s) => s.selected);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const s = useStore.getState();
      const mod = e.ctrlKey || e.metaKey;
      if (mod && e.key.toLowerCase() === 's') {
        e.preventDefault();
        if (s.dirty()) void s.save();
        return;
      }
      if (typing(e)) return;
      if (mod && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        if (e.shiftKey) s.redo();
        else s.undo();
      } else if (mod && e.key.toLowerCase() === 'y') {
        e.preventDefault();
        s.redo();
      } else if (mod && e.key.toLowerCase() === 'd' && s.selected) {
        e.preventDefault();
        duplicateSelected();
      } else if (mod && e.key.toLowerCase() === 'c' && s.selected) {
        const src = s.selected.sid && s.selected.owner.kind !== 'theme' ? sourceOf(s.selected.owner) : null;
        clipboard = stripIds((src && s.selected.sid ? outerOf(src, s.selected.sid) : null) ?? s.selected.el.outerHTML.replace(/\sdata-ve-(id|ops)="[^"]*"/g, ''));
        s.toast(t('Element copied.'));
      } else if (mod && e.key.toLowerCase() === 'v' && clipboard) {
        e.preventDefault();
        insertHtml(clipboard, 'after', t('Pasted element'));
      } else if ((e.key === 'Delete' || e.key === 'Backspace') && s.selected && !mod) {
        e.preventDefault();
        removeSelected();
      } else if (e.key === 'Escape' && s.selected) {
        selectParent();
      } else if (e.altKey && e.key === 'ArrowUp') {
        moveSibling(-1);
      } else if (e.altKey && e.key === 'ArrowDown') {
        moveSibling(1);
      } else if (!mod && !e.altKey && ['1', '2', '3'].includes(e.key)) {
        useStore.setState({ device: (['desktop', 'tablet', 'mobile'] as const)[Number(e.key) - 1] });
      } else if (!mod && e.key.toLowerCase() === 'p') {
        useStore.setState({ interact: !s.interact });
      }
    };
    window.addEventListener('keydown', onKey);
    const leave = (e: BeforeUnloadEvent) => {
      if (useStore.getState().dirty()) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', leave);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('beforeunload', leave);
    };
  }, []);

  return (
    <div className={'ve-app' + (interact ? ' ve-preview-mode' : '')}>
      <TopBar />
      <div className="ve-main">
        <aside className="ve-side">
          <div className="ve-tabs">
            {TABS.map((tab) => (
              <button key={tab.key} className={left === tab.key ? 'on' : ''} onClick={() => useStore.setState({ left: tab.key })} title={t(tab.label)}>
                {tab.icon} {t(tab.label)}
              </button>
            ))}
          </div>
          {remoteRev !== base.rev && remoteRev > base.rev && (
            <div className="ve-banner">
              🤖 {t('The site changed while you edit. Publishing merges both.')}
            </div>
          )}
          <div className="ve-scroll" style={{ display: 'flex', flexDirection: 'column' }}>
            {left === 'layers' && <Layers />}
            {left === 'add' && <AddPanel />}
            {left === 'pages' && <PagesPanel />}
            {left === 'components' && <ComponentsPanel />}
            {left === 'design' && <DesignPanel />}
            {left === 'seo' && <SeoPanel />}
            {left === 'ai' && <AiPanel />}
          </div>
        </aside>
        <Canvas />
        <aside className="ve-side right" key={selected?.sel ?? 'none'}>
          <Inspector />
        </aside>
      </div>
      <Modals />
      <div className="ve-toasts">
        {toasts.map((x) => (
          <div key={x.id} className={'ve-toast ' + x.kind}>
            {x.text}
          </div>
        ))}
      </div>
    </div>
  );
};
