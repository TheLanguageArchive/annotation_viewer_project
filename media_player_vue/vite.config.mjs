import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'

export default defineConfig({
  plugins: [
    vue({
      customElement: true
    })
  ],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    minify: true,
    lib: {
      entry: fileURLToPath(new URL('./src/main.js', import.meta.url)),
      name: 'FlatMediaPlayer',
      fileName: () => 'media-player.js',
      formats: ['iife']
    },
    rollupOptions: {
      output: {
        extend: true
      }
    }
  },
  define: {
    'process.env.NODE_ENV': '"production"'
  }
})
