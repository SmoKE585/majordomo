import { defineConfig } from 'vite';

/**
 * Dashboard charts chunk.
 *
 * ApexCharts is only needed on the dashboard. A separate IIFE chunk keeps the
 * main admin bundle lean; js/admin/modules/admin_panel/101_dashboard_charts.js
 * loads assets/admin/charts.js on first use.
 *
 * `emptyOutDir` must stay false — the main build owns the directory.
 */
export default defineConfig({
  base: './',
  publicDir: false,
  build: {
    outDir: 'assets/admin',
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
