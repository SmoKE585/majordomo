import { defineConfig } from 'vite';

/**
 * Admin bundle build.
 *
 * Output is committed to public/assets/admin so the PHP application does not need
 * Node.js at runtime. Run `npm run build` after changing resources/js or resources/css.
 */
export default defineConfig({
  base: './',
  publicDir: false,
  build: {
    outDir: 'public/assets/admin',
    // The code editor is a separate chunk in the same directory. The main
    // build must not wipe it when only `build:admin` is run.
    emptyOutDir: false,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2018',
    chunkSizeWarningLimit: 700,
    rollupOptions: {
      input: 'resources/js/app.js',
      output: {
        format: 'iife',
        entryFileNames: 'admin.js',
        assetFileNames: (assetInfo) => {
          const name = (assetInfo.names && assetInfo.names[0]) || assetInfo.name || '';
          if (name.endsWith('.css')) {
            return 'admin.css';
          }
          if (/\.(woff2?|ttf|eot|otf)$/i.test(name)) {
            return 'fonts/[name][extname]';
          }
          return 'media/[name][extname]';
        },
      },
    },
  },
});

