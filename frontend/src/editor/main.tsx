import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { readConfig } from '@/admin/lib/api';
import { EditorApp } from './EditorApp';
import type { EditorConfig } from './types';
import './editor.css';

const root = document.getElementById('sve-root');
if (root) {
  createRoot(root).render(
    <StrictMode>
      <EditorApp config={readConfig<EditorConfig>('sve-config')} />
    </StrictMode>,
  );
}
