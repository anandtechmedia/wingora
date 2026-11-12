import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  server: {
    port: 5173,
    strictPort: true,
    // Same-origin API in development so Sanctum's session and XSRF cookies apply (VITE_API_MODE=live).
    proxy: Object.fromEntries(['/api', '/sanctum'].map((p) => [p, { target: process.env.WINGORA_API_ORIGIN ?? 'http://127.0.0.1:8000', changeOrigin: false }])),
  },
  preview: {
    proxy: Object.fromEntries(['/api', '/sanctum'].map((p) => [p, { target: process.env.WINGORA_API_ORIGIN ?? 'http://127.0.0.1:8000', changeOrigin: false }])),
  },
  build: { target: 'es2022', sourcemap: true },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
});
