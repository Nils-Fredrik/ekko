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
    await expect(
        funnel.getByText('Google Analytics 4 sessions where the visit came from an AI service.'),
    ).toBeVisible();
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

    await page.setViewportSize({ width: 320, height: 720 });
    const traffic = page.getByTestId('crawler-traffic');
    const sources = traffic.getByRole('region', { name: await traffic.locator('caption').innerText() });
    await traffic.getByRole('button', { name: 'Show as table' }).focus();
    await page.keyboard.press('Tab');
    await expect(sources).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => sources.evaluate((element) => element.scrollLeft)).toBeGreaterThan(0);
    expect(errors).toEqual([]);
});

test('filtering to one day refreshes the funnel and shows the recorded robot hits', async ({ page }) => {
    const { brand, day, email } = fixture();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto(`/brands/${brand}/crawlers`);
    const cards = page.getByTestId('crawler-kpis').getByTestId('kpi-card');
    await expect(cards.first().getByText('47', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Add or change filters' }).click();
    await page.getByRole('combobox', { name: 'Period', exact: true }).selectOption('custom');
    await page.getByLabel('From', { exact: true }).fill(day);
    await page.getByLabel('To', { exact: true }).fill(day);
    const filtered = page.waitForResponse((response) => {
        const url = new URL(response.url());
        return (
            url.pathname.endsWith('/crawlers') &&
            url.searchParams.get('from') === day &&
            response.request().headers()['x-inertia-partial-data']?.includes('period')
        );
    });
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    const { props } = await (await filtered).json();
    expect(props.funnel?.current.fetched.value).toBe(35);
    await expect(page).toHaveURL(new RegExp(`from=${day}`));
    await expect(cards.first().getByText('35', { exact: true })).toBeVisible();
    await expect(cards.nth(2).getByText('8', { exact: true })).toBeVisible();

    const robots = page.getByTestId('crawler-robots');
    await robots.locator('figure').scrollIntoViewIfNeeded();
    await expect(robots.locator('figure[data-chart-ready="true"]')).toBeVisible();
    await robots.getByRole('button', { name: 'Show as table' }).click();
    await expect(robots.locator('table tbody')).toContainText('30');
    await page.keyboard.press('Tab');
    await expect(robots.getByRole('region', { name: await robots.locator('h3').innerText() })).toBeFocused();
    expect(errors).toEqual([]);
});
