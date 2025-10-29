import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost/sustainable_travel-backend',
        changeOrigin: true
      }
    }
  },
  build: {
    outDir: path.resolve(__dirname, '../BackEnd/public_assets'),
    emptyOutDir: false
  }
})
