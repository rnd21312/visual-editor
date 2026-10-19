import { createRoot } from 'react-dom/client';
import { readConfig } from './config';
import { initStore, useStore } from './store';
import { setLanguage, isRtl } from './i18n';
import { startLive } from './live';
import { App } from './App';
import './styles.css';

const cfg = readConfig();
initStore(cfg);

// Unsaved work of an earlier session is dropped before the preview loads. The language file and that request
// run side by side, and the editor shell is on screen as soon as the language is known; only the preview
// waits for the reset (see Canvas: previewReady).
const reset = useStore
  .getState()
  .api.del('working')
  .catch(() => undefined)
  .finally(() => useStore.setState({ previewReady: true }));

void setLanguage(cfg.language).then(() => {
  document.documentElement.dir = isRtl() ? 'rtl' : 'ltr';
  createRoot(document.getElementById('sve-root')!).render(<App />);
  startLive();
  void reset;
});
