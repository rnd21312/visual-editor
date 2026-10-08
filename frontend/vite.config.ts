import path from 'node:path';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Visual editor plugin → ../dist: the editor window and the tiny public-page runtime.
export default defineConfig({
  base: './',
  plugins: [react()],
  resolve: { alias: { '@': path.resolve(__dirname, 'src') } },
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        'visual-editor': path.resolve(__dirname, 'src/editor/main.tsx'),
        'visual-runtime': path.resolve(__dirname, 'src/runtime/visual.ts'),
      },
    },
  },
  server: {
    host: 'localhost',
    port: 5175,
    strictPort: true,
    cors: true,
    origin: 'http://localhost:5175',
  },
});
