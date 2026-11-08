import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Dev: frontend di http://localhost:5173, API Laravel di http://localhost:8000 (di-proxy lewat /api).
// Origin WebAuthn = http://localhost:5173 (harus sama dengan CASELOCK_ORIGINS di backend/.env).
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': { target: 'http://localhost:8000', changeOrigin: false },
    },
  },
})
