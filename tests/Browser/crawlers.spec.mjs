import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { databaseEnv } from './database.mjs';

// U09: the robots and traffic page shows the funnel «fetched by AI → cited → visits» with the right figures for the
// synthetic data and explains how each step is measured (docs/ui-forbedring.md 7.6).

const fixture = () =>
    JSON.parse(
        execFileSync('php', ['tests/Browser/seed-crawlers.php'], {
            env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
            encoding: 'utf8',
        }),
    );

test('the funnel shows the measured figures and how each step is measured', async ({ page }) => {
    const { brand, email } = fixture();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto(`/brands/${brand}/crawlers`);
    const cards = page.getByTestId('crawler-kpis').getByTestId('kpi-card');
    await expect(cards).toHaveCount(3);
    await expect(cards.nth(0)).toContainText('Fetched by AI');
    await expect(cards.nth(0)).toContainText('47');
    await expect(cards.nth(1)).toContainText('Cited in answers');
    await expect(cards.nth(1)).toContainText('n 20');
    await expect(cards.nth(2)).toContainText('Visits from AI');
    await expect(cards.nth(2)).toContainText('11');

    const funnel = page.getByTestId('crawler-funnel');
    await expect(funnel.locator('figure[data-chart-ready="true"]')).toBeVisible();
    await expect(funnel.getByText('Requests from AI robots to the brand', { exact: false })).toBeVisible();
    await expect(funnel.getByText('out of 20 answers in the period', { exact: false })).toBeVisible();
    await expect(funnel.getByText('Google Analytics 4 sessions where the visit came from an AI service.')).toBeVisible();
    await funnel.getByRole('button', { name: 'Show as table' }).click();
    const rows = funnel.locator('table tbody tr');
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(0)).toContainText('47');
    await expect(rows.nth(1)).toContainText('4');
    await expect(rows.nth(2)).toContainText('11');
    // Different units: no step is presented as a share of the previous one.
    await expect(funnel.locator('table thead')).not.toContainText('previous');

    // Charts load when they come near the viewport.
    for (const id of ['crawler-robots', 'crawler-traffic']) {
        const section = page.getByTestId(id);
        await section.locator('figure').scrollIntoViewIfNeeded();
        await expect(section.locator('figure[data-chart-ready="true"]')).toBeVisible();
    }
    const pages = page.getByTestId('crawler-page');
    await expect(pages.first()).toContainText('crawled-coffee.example.test/guide');
    await expect(pages.first()).toContainText('25');
    expect(errors).toEqual([]);
});
