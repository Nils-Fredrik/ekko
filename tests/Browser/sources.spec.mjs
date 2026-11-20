import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { databaseEnv } from './database.mjs';

// U08: the sources page ranks domains by ownership, links a source gap to its action, shows the source types,
// and clicking a domain shows the answers that cite it (docs/ui-forbedring.md 7.5).

const fixture = () =>
    JSON.parse(
        execFileSync('php', ['tests/Browser/seed-sources.php'], {
            env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
            encoding: 'utf8',
        }),
    );

test('clicking a domain shows the answers that cite it', async ({ page }) => {
    const { brand, action, email } = fixture();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto(`/brands/${brand}/sources`);
    const ranked = page.getByTestId('sources-ranked');
    await expect(ranked.locator('figure[data-chart-ready="true"]')).toBeVisible();
    await expect(ranked.locator('ol').getByText('Competitor: Browser rival')).toBeVisible();
    await expect(ranked.locator('ol').getByText('Own', { exact: true })).toBeVisible();
    await expect(page.getByTestId('sources-types').locator('figure[data-chart-ready="true"]')).toBeVisible();
    await expect(page.getByTestId('gap-action')).toHaveAttribute(
        'href',
        new RegExp(`/brands/${brand}/actions/${action}$`),
    );

    await page.getByRole('button', { name: 'Show the answers citing coffee-news.example.test' }).first().click();
    const answers = page.getByTestId('source-answers');
    await expect(answers.getByRole('heading', { name: 'Answers citing coffee-news.example.test' })).toBeVisible();
    await expect(answers.getByText('Which coffee roaster do you recommend?')).toHaveCount(2);
    await expect(answers.getByRole('link', { name: 'https://coffee-news.example.test/best-roasters' })).toBeVisible();
    await expect(answers.getByText('Names the brand', { exact: false })).toBeVisible();
    await expect(page).toHaveURL(/domain=coffee-news\.example\.test/);

    await answers.getByRole('button', { name: 'Close' }).click();
    await expect(answers).toHaveCount(0);
    expect(errors).toEqual([]);
});
