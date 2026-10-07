import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'node:path'

export default defineConfig(({ command }) => ({
  plugins: [react()],
  // The production bundle uses the React that WordPress ships (wp-element) instead of embedding its own copy.
  resolve: command === 'build' ? {
    alias: [
      { find: /^react\/jsx-runtime$/, replacement: resolve(import.meta.dirname, 'src/wp-shims/react-jsx-runtime.js') },
      { find: /^react-dom\/client$/, replacement: resolve(import.meta.dirname, 'src/wp-shims/react-dom.js') },
      { find: /^react-dom$/, replacement: resolve(import.meta.dirname, 'src/wp-shims/react-dom.js') },
      { find: /^react$/, replacement: resolve(import.meta.dirname, 'src/wp-shims/react.js') },
    ],
  } : {},
  base: './',
  build: {
    outDir: resolve(import.meta.dirname, '../plugin/community-race-calendar/assets'),
    emptyOutDir: true,
    cssCodeSplit: false,
    // Chunks must not import the entry file: WordPress appends a ?ver= query to it, so the entry would be evaluated twice.
    modulePreload: false,
    chunkSizeWarningLimit: 1000,
    rollupOptions: {
      input: resolve(import.meta.dirname, 'src/main.jsx'),
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (assetInfo) => assetInfo.name?.endsWith('.css') ? 'app.css' : '[name][extname]',
        format: 'es',
        codeSplitting: {
          groups: [{ name: 'preload-helper', test: /vite\/preload-helper/, priority: 10 }],
        },
      }
    }
  },
  test: {
    environment: 'jsdom',
    setupFiles: './src/test/setup.js'
  }
}))
