import { defineConfig } from 'vite';

/**
 * Code editor chunk.
 *
 * The editor is built separately from the main admin bundle so pages without
 * an editor (dashboard, settings list, etc.) never download CodeMirror.
 * `emptyOutDir` must stay false — the main build owns the directory.
 */
export default defineConfig({
  base: './',
  publicDir: false,
  build: {
    outDir: 'public/assets/admin',
    emptyOutDir: false,
    cssCodeSplit: false,
    sourcemap: false,
    reportCompressedSize: false,
    target: 'es2018',
    chunkSizeWarningLimit: 1200,
    rollupOptions: {
      input: 'resources/js/codeeditor/index.js',
      output: {
        format: 'iife',
        entryFileNames: 'codeeditor.js',
        assetFileNames: (assetInfo) => {
          const name = (assetInfo.names && assetInfo.names[0]) || assetInfo.name || '';
          if (name.endsWith('.css')) {
            return 'codeeditor.css';
          }
          return 'media/[name][extname]';
        },
      },
    },
  },
});
