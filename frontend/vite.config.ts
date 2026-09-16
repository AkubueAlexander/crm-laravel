import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';

// 16.2: server.proxy makes the Vite dev server same-origin with the Laravel
// API from the browser's point of view, so Sanctum's cookie-based SPA auth
// (CSRF cookie + session cookie) works identically to production without any
// CORS workarounds — /api and /sanctum requests are forwarded to Laravel.
//
// Routing (0.0): TanStack Router used in code-based mode (createRootRoute /
// createRoute) for Phase 1's small route set — see src/router.tsx. Switch to
// file-based routing + the @tanstack/router-plugin codegen once the route
// count grows enough to want it; no vite plugin needed for code-based mode.
export default defineConfig({
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
    server: {
        port: 5173,
        proxy: {
            '/api': { target: 'http://localhost:8000', changeOrigin: true },
            '/sanctum': { target: 'http://localhost:8000', changeOrigin: true },
        },
    },
    build: {
        outDir: '../public/build',
        manifest: true,
    },
});
