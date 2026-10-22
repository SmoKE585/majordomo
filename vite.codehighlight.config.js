import { defineConfig } from 'vite';

/**
 * Read-only code sample highlighter chunk.
 *
 * MDJUI::codeSample() blocks are lightweight, so highlight.js lives in its own
 * lazy chunk instead of the main admin bundle. `emptyOutDir` must stay false —
 * the main build owns the directory.
 */
export default defineConfig({
  base: './',
  publicDir: false,
  build: {
    outDir: 'public/assets/admin',
    emptyOutDir: false,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2018',
    chunkSizeWarningLimit: 700,
    rollupOptions: {
      input: 'resources/js/codehighlight/index.js',
      output: {
        format: 'iife',
        entryFileNames: 'codehighlight.js',
        assetFileNames: (assetInfo) => {
          const name = (assetInfo.names && assetInfo.names[0]) || assetInfo.name || '';
          if (name.endsWith('.css')) {
            return 'codehighlight.css';
          }
          return 'media/[name][extname]';
        },
      },
    },
  },
});
