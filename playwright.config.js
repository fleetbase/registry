import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.E2E_PORT || 8123);

export default defineConfig({
    testDir: './e2e',
    timeout: 60_000,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'phone', use: { ...devices['Pixel 7'] }, grep: /@phone/ },
    ],
    webServer: {
        command: './scripts/e2e-server.sh',
        url: `http://127.0.0.1:${port}/up`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        env: { E2E_PORT: String(port) },
    },
});
