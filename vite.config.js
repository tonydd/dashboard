import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vitejs.dev/config/
export default defineConfig({
  build: {
    minify: false
  },
  plugins: [
    vue(),
    vueDevTools(),
  ],
  base: process.env.VITE_BASE_URL || '/',
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  server: {
    fs: {
      // Allow serving files from one level up to the project root
      allow: [
          '/home/tony/projets/dashboard/node_modules/@mdi/font/fonts',
          '/home/tony/projets/dashboard/',
          '/home/tony/node_modules'
      ],
    },
  },
})
