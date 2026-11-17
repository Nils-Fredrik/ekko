import { defineConfig } from '@playwright/test';
import { databaseEnv } from './tests/Browser/database.mjs';

const port = Number(process.env.BROWSER_PORT ?? 8010);
export default defineConfig({
    testDir: './tests/Browser',
    globalSetup: './tests/Browser/setup.mjs',
    workers: 1,
    retries: 0,
    forbidOnly: true,
    reporter: 'line',
    timeout: 30000,
    use: { baseURL: `http://127.0.0.1:${port}`, trace: 'off', screenshot: 'off', video: 'off' },
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${port}`,
        url: `http://127.0.0.1:${port}/up`,
        reuseExistingServer: false,
        timeout: 30000,
        env: { ...databaseEnv, APP_URL: `http://127.0.0.1:${port}`, SESSION_SECURE_COOKIE: 'false' },
    },
});
