import { defineConfig } from '@playwright/test';

// 15.6: E2E runs against its own Laravel (port 8010, APP_ENV=e2e -> .env.e2e -> database crm_erp_e2e)
// and its own Vite dev server (port 5183) proxying to it. Never touches the dev servers or the dev database.
const WEB_PORT = 5183;
const API_PORT = 8010;

export default defineConfig({
    testDir: './e2e',
    testMatch: '**/*.e2e.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: Boolean(process.env.CI),
    timeout: 60000,
    expect: { timeout: 10000 },
    reporter: [['list']],
    use: {
        baseURL: `http://localhost:${WEB_PORT}`,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
    webServer: [
        {
            // Prepare the database first (migrate:fresh + seed), then serve the API.
            command: `php artisan e2e:prepare --env=e2e && php artisan serve --host=127.0.0.1 --port=${API_PORT} --env=e2e`,
            cwd: '..',
            url: `http://127.0.0.1:${API_PORT}/sanctum/csrf-cookie`,
            reuseExistingServer: false,
            timeout: 240000,
        },
        {
            command: `npx vite --port ${WEB_PORT} --strictPort`,
            url: `http://localhost:${WEB_PORT}`,
            env: { E2E_API_TARGET: `http://127.0.0.1:${API_PORT}` },
            reuseExistingServer: false,
            timeout: 120000,
        },
    ],
});
