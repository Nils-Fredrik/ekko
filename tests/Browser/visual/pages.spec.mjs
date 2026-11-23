import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { pages } from './pages.mjs';
import { criticalViolations } from './accessibility.mjs';
import { observeInertia, settleCharts } from './settle.mjs';

// Pages rendered in Layouts/AppLayout.vue. Each has the sidebar (U17, docs/ux-arbeidsflate.md 4.1).
const pagesRoot = 'resources/js/Pages';
const vueFiles = (dir) =>
    readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
        entry.isDirectory()
            ? vueFiles(join(dir, entry.name))
            : entry.name.endsWith('.vue')
              ? [join(dir, entry.name)]
              : [],
    );
const appLayoutPages = new Set(
    vueFiles(pagesRoot)
        .filter((file) => readFileSync(file, 'utf8').includes("from '@/Layouts/AppLayout.vue'"))
        .map((file) => relative(pagesRoot, file).replace(/\.vue$/, '')),
);

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
            await observeInertia(page);
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
            await settleCharts(page, { inertia: !!component, shared: name === 'shared-report' });
            if (component) await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
            if (component && (component.startsWith('Brands/') || component.startsWith('Reports/') || ['Home', 'Partner/Index', 'Inbox/Index'].includes(component))) {
                const header = page.getByTestId('workspace-header');
                await expect(header.getByRole('heading', { level: 1 })).toHaveCSS('font-size', '26px');
                await expect(header.locator('p').first()).toHaveText(/.+ · .+/);
            }
            if (name === 'competitor-alias') {
                await page.getByTestId('competitor-table').getByRole('button', { name: 'Harbour Tea', exact: true }).click();
                const panel = page.getByTestId('competitor-panel');
                await panel.getByTestId('alias-input').fill('Fjord Tea');
                await expect(panel.getByTestId('alias-preview')).toContainText('4 hits');
                await expect(panel.locator('mark')).toHaveCount(3);
                await expect(panel.getByText('4 of the hits are currently Fjord Tea')).toBeVisible();
                await panel.getByTestId('alias-input').blur();
            }
            if (name === 'brand') {
                const link = page.getByTestId('health-header').getByRole('link', { name: /1 new action awaits review.*Review actions/ });
                await expect(link).toBeVisible();
                await expect(link).toHaveAttribute('href', /\/brands\/\d+\/actions$/);
            }
            if (name === 'inbox') {
                const row = page.getByTestId('inbox-row-entity').filter({ hasText: 'Havnelys Studio' });
                await expect(row.getByRole('heading')).toHaveText(/Havnelys Studio was mentioned in \d+%/);
                await expect(row).toContainText(/17 answers · 1 engine · Fjord Tea · Norway · 12:00/);
            }
            if (name === 'brand' && info.project.name.startsWith('desktop-')) {
                const bar = page.getByTestId('analysis-filters');
                const market = await bar.getByRole('combobox', { name: /Switch market/ }).boundingBox();
                const period = await bar.getByRole('combobox', { name: 'Change period' }).boundingBox();
                const filter = await bar.getByRole('button', { name: '+ Filter', exact: true }).boundingBox();
                const views = await bar.locator('summary').boundingBox();
                expect(market.x).toBeLessThan(period.x);
                expect(period.x).toBeLessThan(filter.x);
                expect(filter.x + filter.width).toBeLessThan(views.x);
                expect(Math.abs(filter.y - views.y)).toBeLessThan(12);
            }
            if (appLayoutPages.has(component)) {
                const mobile = info.project.name.startsWith('mobile-');
                await expect(page.getByTestId(mobile ? 'open-sidebar' : 'sidebar')).toBeVisible();
                if (mobile && (component.startsWith('Brands/Setup/') || component === 'Inbox/Index')) {
                    const fits = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
                    expect(fits, 'Only the tables and filter lists may scroll horizontally').toBe(true);
                }
                if (name === 'reports' || name === 'report') {
                    await expect(page.getByText(/1 Oct.*3 Oct/).first()).toBeVisible();
                    await expect(page.locator('main')).not.toContainText(/\d{4}-\d{2}-\d{2}/);
                }
                if (name === 'admin-billing') {
                    await expect(page.getByRole('cell', { name: /USD\s*10\.00/ }).first()).toBeVisible();
                    await expect(page.getByRole('table')).not.toContainText(/\d+\.\d{8}/);
                }
                if (name === 'admin-engines') {
                    await expect(page.getByText(/Current price:.*USD\s*0\.0100/).first()).toBeVisible();
                    await expect(page.getByText(/Scheduled price:.*USD\s*0\.0246.*Oct/).first()).toBeVisible();
                }
                if (name === 'setup-markets') {
                    await expect(page.locator('[data-detail-panel]')).toHaveCount(0);
                    const grid = await page.getByRole('grid', { name: 'Markets and measurement plan' }).boundingBox();
                    const matrix = await page.getByTestId('plan-matrix').boundingBox();
                    expect(matrix.y - (grid.y + grid.height)).toBeLessThanOrEqual(24);
                }
                if (!mobile && name === 'setup-prompts') {
                    const grid = page.getByRole('grid', { name: 'Prompts and topics' });
                    const topics = page.getByRole('button', { name: 'All topics' }).locator('xpath=ancestor::aside');
                    const size = await grid.evaluate((el) => ({
                        visible: el.parentElement.clientWidth,
                        content: el.parentElement.scrollWidth,
                    }));
                    expect(size.visible).toBe(size.content);
                    expect((await topics.boundingBox()).width).toBeLessThan(size.visible);
                    for (const heading of ['Prompt text', 'Topic', 'Market', 'Core']) {
                        await expect(grid.getByRole('columnheader', { name: heading, exact: true })).toBeVisible();
                    }
                    await expect(grid.getByRole('gridcell', { name: 'false', exact: true })).toHaveCount(0);
                    await expect(grid.locator('[aria-label*="mentioned in"], [aria-label*="not measured"]').first()).toBeVisible();
                }
                if (!mobile && component.startsWith('Brands/') && component !== 'Brands/Index')
                    await expect(page.getByTestId('sidebar').locator('[aria-current="page"]')).toHaveCount(1);
            }
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
