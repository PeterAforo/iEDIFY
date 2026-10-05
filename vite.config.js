import { defineConfig } from 'vite';

export default defineConfig({
  base: '/build/',
  publicDir: false,
  build: {
    outDir: 'public/build',
    manifest: true,
    rollupOptions: { input: 'resources/assets/scripts/app.js' },
  },
  css: { preprocessorOptions: { scss: { silenceDeprecations: ['import', 'global-builtin', 'color-functions'] } } },
});
