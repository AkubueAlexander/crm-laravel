import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';


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


const apiTarget = process.env.E2E_API_TARGET ?? 'http://localhost:8000';

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
            '/api': { target: apiTarget, changeOrigin: true },
            '/sanctum': { target: apiTarget, changeOrigin: true },
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
