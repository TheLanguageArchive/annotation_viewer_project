import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [
    vue({
      customElement: true
    })
  ],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    lib: {
      entry: './src/main.js',
      name: 'AnnotationViewer',
      fileName: 'annotation-viewer',
      formats: ['iife']
    },
    rollupOptions: {
      output: {
        extend: true,
        globals: {
          vue: 'Vue'
        }
      }
    }
  },
  define: {
    'process.env.NODE_ENV': '"production"'
  }
})
