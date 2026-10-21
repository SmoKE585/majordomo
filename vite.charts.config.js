import { defineConfig } from 'vite';

/**
 * Charts chunk.
 *
 * ApexCharts is shared by the dashboard and the history drawer. A separate
 * IIFE chunk keeps the main admin bundle lean;
 * src/admin/js/modules/admin_panel/100_chart_runtime.js loads /assets/admin/charts.js
 * on first use.
 *
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
    target: 'es2018',
    chunkSizeWarningLimit: 1200,
    rollupOptions: {
      input: 'src/admin/charts-entry.js',
      output: {
        format: 'iife',
        entryFileNames: 'charts.js',
        assetFileNames: (assetInfo) => {
          const name = (assetInfo.names && assetInfo.names[0]) || assetInfo.name || '';
          if (name.endsWith('.css')) {
            return 'charts.css';
          }
          return 'media/[name][extname]';
        },
      },
    },
  },
});
