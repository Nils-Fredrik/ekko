import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { pages } from './pages.mjs';
import { criticalViolations } from './accessibility.mjs';

for (const [name, component, role, path] of pages) {
    test.describe(name, () => {
        test.use({ storageState: role === 'guest' ? { cookies: [], origins: [] } : `.ui-auth/${role}.json` });
        test(`${name}: visual and accessibility`, async ({ page, baseURL }, info) => {
            const theme = info.project.name.endsWith('-dark') ? 'dark' : 'light';
            await page.addInitScript((value) => localStorage.setItem('ekko.theme', value), theme);
            await page.clock.setFixedTime(new Date('2026-10-16T12:00:00Z'));
            await page.route('**/*', (route) =>
                new URL(route.request().url()).origin === new URL(baseURL).origin ? route.continue() : route.abort(),
            );
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            const response = await page.goto(path());
            expect(response.status()).toBe(name === 'shared-missing' ? 404 : 200);
            // A login redirect must never silently become the baseline for an inaccessible page.
            if (component)
                expect(
                    await page
                        .locator('#app')
                        .getAttribute('data-page')
                        .then(JSON.parse)
                        .then((p) => p.component),
                ).toBe(component);
            await page.waitForLoadState('networkidle');
            if (component) await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
            await page.evaluate(() => document.fonts.ready);
            // Bring lazy chart containers into view, then wait for their SVG before capturing the whole page.
            for (const figure of await page.locator('figure[data-chart-ready]').all()) {
                await figure.scrollIntoViewIfNeeded();
                await expect(figure).toHaveAttribute('data-chart-ready', 'true');
            }
            await page.evaluate(() => window.scrollTo(0, 0));
            const scan = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
                .analyze();
            await info.attach('accessibility', {
                body: JSON.stringify({ url: new URL(page.url()).pathname, violations: scan.violations }, null, 2),
                contentType: 'application/json',
            });
            expect.soft(criticalViolations(scan), 'Critical axe violations').toEqual([]);
            expect.soft(errors, 'Browser JavaScript errors').toEqual([]);
            await info.attach('page', {
                body: await page.screenshot({ fullPage: true, animations: 'disabled' }),
                contentType: 'image/png',
            });
            await expect(page).toHaveScreenshot(`${name}.png`, { fullPage: true });
        });
    });
}
