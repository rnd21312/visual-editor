import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The editor app: loaded by EditorScreen.php from dist/.vite/manifest.json.
export default defineConfig({
  plugins: [react()],
  base: './',
  server: { port: 5175, strictPort: true, cors: true, origin: 'http://localhost:5175' },
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    manifest: true,
    target: 'es2022',
    chunkSizeWarningLimit: 2000,
    rollupOptions: { input: 'src/editor/main.tsx' },
  },
});
