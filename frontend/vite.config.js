import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: { '@': '/src' },
    },
    server: {
        port: 5173,
        // Proxy /api requests to Laravel during development
        proxy: {
            '/api': {
                target: process.env.VITE_API_URL ?? 'http://localhost:8000',
                changeOrigin: true,
            },
        },
    },
})
