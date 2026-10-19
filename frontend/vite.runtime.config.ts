import { defineConfig } from 'vite';

// The public runtime: one small self-contained script (dist/runtime.js) that Frontend.php prints inline.
export default defineConfig({
  build: {
    outDir: '../dist',
    emptyOutDir: false,
    target: 'es2019',
    minify: 'esbuild',
    lib: { entry: 'src/runtime/index.ts', name: 'sveRuntime', formats: ['iife'], fileName: () => 'runtime.js' },
  },
});
