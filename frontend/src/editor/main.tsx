import { createRoot } from 'react-dom/client';
import { readConfig } from './config';
import { initStore, useStore } from './store';
import { setLanguage, isRtl } from './i18n';
import { startLive } from './live';
import { App } from './App';
import './styles.css';

const cfg = readConfig();
setLanguage(cfg.language);
document.documentElement.dir = isRtl() ? 'rtl' : 'ltr';
initStore(cfg);

const root = createRoot(document.getElementById('sve-root')!);
// Unsaved work of an earlier session is dropped before the preview loads.
void useStore
  .getState()
  .api.del('working')
  .catch(() => undefined)
  .finally(() => {
    root.render(<App />);
    startLive();
  });
