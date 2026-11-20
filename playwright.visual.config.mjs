import { defineConfig } from '@playwright/test';
import base from './playwright.config.mjs';
import { fixtureEnvironment } from './tests/Browser/visual/environment.mjs';

const endpoint = process.env.UI_BROWSER_WS;
if (!endpoint) throw new Error('UI_BROWSER_WS must point to the pinned Linux browser. See docs/ui-quality.md.');
export default defineConfig({
    ...base,
    testDir: './tests/Browser/visual',
    testIgnore: [],
    workers: 2,
    fullyParallel: true,
    globalSetup: './tests/Browser/visual/setup.mjs',
    globalTeardown: './tests/Browser/visual/teardown.mjs',
    outputDir: 'test-results/ui-quality',
    reporter: [['line'], ['html', { outputFolder: 'playwright-report/ui-quality', open: 'never' }]],
    timeout: 60000,
    expect: { timeout: 10000, toHaveScreenshot: { animations: 'disabled', caret: 'hide', maxDiffPixels: 100 } },
    snapshotPathTemplate: '{testDir}/snapshots/{projectName}/{arg}{ext}',
    use: {
        ...base.use,
        connectOptions: { wsEndpoint: endpoint, exposeNetwork: '<loopback>' },
        locale: 'en-GB',
        timezoneId: 'UTC',
        reducedMotion: 'reduce',
        deviceScaleFactor: 1,
        serviceWorkers: 'block',
    },
    projects: ['dark', 'light'].flatMap((theme) => [
        { name: `desktop-${theme}`, use: { viewport: { width: 1440, height: 1000 }, colorScheme: theme } },
        {
            name: `mobile-${theme}`,
            use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, colorScheme: theme },
        },
    ]),
    webServer: { ...base.webServer, env: { ...base.webServer.env, ...fixtureEnvironment } },
});
