import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

// El build se publica dentro de backend/public/app para que Laravel lo sirva en el mismo dominio que la API.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    base: env.VITE_BASE || (mode === 'development' ? '/' : '/app/'),
    plugins: [react()],
    build: {
      outDir: '../backend/public/app',
      emptyOutDir: true,
    },
    server: {
      port: 5173,
      // En desarrollo, /api se reenvía al servidor de Laravel (php artisan serve).
      proxy: {
        '/api': { target: env.VITE_PROXY_API || 'http://127.0.0.1:8000', changeOrigin: true },
        // Laravel también sirve las fotos de los productos desde backend/public/imagenes.
        '/imagenes': { target: env.VITE_PROXY_API || 'http://127.0.0.1:8000', changeOrigin: true },
      },
    },
  }
})
