import { defineConfig } from 'vite';

export default defineConfig({
  base: '/build/',
  publicDir: false,
  build: {
    outDir: 'public/build',
    manifest: true,
    rollupOptions: { input: { app: 'resources/assets/scripts/app.js', charts: 'resources/assets/scripts/charts.js' } },
  },
  css: { preprocessorOptions: { scss: { silenceDeprecations: ['import', 'global-builtin', 'color-functions'] } } },
});
