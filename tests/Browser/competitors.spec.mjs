import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { databaseEnv } from './database.mjs';

// U07: the competitors page shows the rank over time, the table with visibility, change, rank and sentiment, and
// suggested competitors as cards with «Add». Competitors have the same color here as on the dashboard
// (docs/ui-forbedring.md 7.4).

const fixture = () =>
    JSON.parse(
        execFileSync('php', ['tests/Browser/seed-competitors.php'], {
            env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
            encoding: 'utf8',
        }),
    );

const strokes = (figure) =>
    figure
        .locator('svg path[stroke]')
        .evaluateAll((paths) => paths.map((path) => path.getAttribute('stroke').toLowerCase()));

test('ranking, table, sentiment and suggestions use the dashboard colors', async ({ page }) => {
    const { brand, email } = fixture();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    const firstCompetitorColor = await page.evaluate(() =>
        getComputedStyle(document.documentElement).getPropertyValue('--data-cat-1').trim().toLowerCase(),
    );

    await page.goto(`/brands/${brand}/competitors?days=7`);
    const ranking = page.getByTestId('competitor-ranking').locator('figure[data-chart-ready="true"]');
    await expect(ranking).toBeVisible();
    expect(await strokes(ranking)).toContain(firstCompetitorColor);
    // Tied today: one end label carries both names instead of two labels on top of each other.
    // A long label wraps over several text lines, so the lines are read together.
    const labels = (await ranking.locator('svg text').allTextContents()).join('');
    expect(labels).toContain('Browser competitors brand, Alpha rival');
    expect(labels.split('Alpha rival')).toHaveLength(2);

    const table = page.getByTestId('competitor-table');
    const alpha = table.getByRole('row', { name: /Alpha rival/ });
    await expect(alpha.getByRole('cell').nth(2)).toHaveText(/^1\s*was 2$/);
    await expect(alpha.getByRole('cell').nth(0)).toContainText('n 40');
    await expect(alpha.getByRole('cell').nth(3)).toContainText('40% positive, 40% negative');
    const own = table.getByRole('row', { name: /Browser competitors brand/ });
    await expect(own.getByRole('cell').nth(2)).toContainText('2');
    await expect(own.getByRole('cell').nth(2)).toContainText('was 1');
    await expect(own.getByRole('cell').nth(1)).toContainText('pp');
    const swatch = await alpha
        .getByTestId('competitor-swatch')
        .evaluate((element) => getComputedStyle(element).backgroundColor);
    const expected = await page.evaluate((color) => {
        const probe = document.createElement('span');
        probe.style.backgroundColor = color;
        document.body.append(probe);
        const value = getComputedStyle(probe).backgroundColor;
        probe.remove();

        return value;
    }, firstCompetitorColor);
    expect(swatch).toBe(expected);

    const sentiment = page.getByTestId('competitor-sentiment');
    await sentiment.scrollIntoViewIfNeeded();
    await expect(sentiment.locator('figure[data-chart-ready="true"]')).toBeVisible();

    // The dashboard draws the same competitor with the same color.
    await page.goto(`/brands/${brand}?days=7`);
    const trend = page
        .locator('figure')
        .filter({ has: page.getByRole('img', { name: 'Visibility over time', exact: true }) });
    await trend.scrollIntoViewIfNeeded();
    await expect(trend).toHaveAttribute('data-chart-ready', 'true');
    expect(await strokes(trend)).toContain(firstCompetitorColor);

    // The suggestion wall is gone (U22): a link leads to the queue on the setup page, where the name waits.
    await page.goto(`/brands/${brand}/competitors?days=7`);
    await expect(page.getByTestId('competitor-suggestions')).toHaveCount(0);
    await page.getByTestId('pending-entities').getByRole('link').click();
    await expect(page).toHaveURL(/\/setup\/competitors\?tab=pending/);
    await expect(page.getByTestId('queue-table')).toContainText('Gamma tea house');
    expect(errors).toEqual([]);
});
