import { defineConfig, type Plugin } from 'vite';
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
function publicFormDevRewrite(): Plugin {
    // 9.8c: under npm run dev, /f/{uuid} must open the standalone form entry (form.html), not the SPA.
    return {
        name: 'public-form-dev-rewrite',
        configureServer(server) {
            server.middlewares.use((req, _res, next) => {
                if (req.url && /^\/f\/[0-9a-f-]{36}\/?(\?.*)?$/i.test(req.url)) {
                    req.url = '/form.html';
                }
                next();
            });
        },
    };
}

export default defineConfig({
    plugins: [react(), tailwindcss(), publicFormDevRewrite()],
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
        rollupOptions: {
            input: {
                main: fileURLToPath(new URL('./index.html', import.meta.url)),
                form: fileURLToPath(new URL('./form.html', import.meta.url)),
            },
        },
    },
});
