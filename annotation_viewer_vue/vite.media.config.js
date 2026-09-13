import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
    plugins: [vue()],
    build: {
        outDir: '/tmp/media-player-dist',
        emptyOutDir: true,
        minify: true,
        lib: {
            entry: path.resolve(__dirname, '../media_player_vue/src/main.js'),
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
