import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { databaseEnv } from './database.mjs';

test('the wizard confirms a proposal and opens the brand after the first run', async ({ page }) => {
    test.setTimeout(60000);
    await page.goto('/login');
    await page.locator('#email').fill(process.env.SMOKE_WIZARD_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto('/onboarding/wizard');
    await page.getByLabel('Name').fill('Browser Wizard AS');
    await page.getByLabel('Domain').fill('eksempel.no');
    await page.getByLabel('Country').fill('1');
    await page.getByLabel('Language').fill('nb');
    await page.getByRole('button', { name: 'Analyse the site' }).click();

    await expect(page.getByText(/country.*invalid/i)).toBeVisible();
    await page.getByLabel('Country').fill('NO');
    await page.getByRole('button', { name: 'Analyse the site' }).click();
    await expect(page.getByRole('heading', { name: 'Competitors' })).toBeVisible();
    const competitors = page.getByRole('heading', { name: 'Competitors' }).locator('..');
    await page.getByRole('button', { name: 'Add competitor', exact: true }).click();
    const competitorFields = competitors.locator('input');
    await competitorFields.nth(-2).fill('Ny konkurrent');
    await competitorFields.nth(-1).fill('invalid-domain');
    const topics = page.getByRole('heading', { name: 'Topics', exact: true }).locator('..');
    const topicCount = await topics.locator('input').count();
    await page.getByRole('button', { name: 'Add topic', exact: true }).click();
    await topics.locator('input').last().fill('Trail running');
    await page.getByRole('button', { name: 'Add topic', exact: true }).click();
    await topics.locator('input').last().fill('Road running');
    await topics.getByRole('button', { name: 'Remove', exact: true }).nth(topicCount).click();
    await page.getByRole('button', { name: 'Add topic', exact: true }).click();
    await topics.locator('input').last().fill('Walking');
    await expect(topics.locator('input')).toHaveCount(topicCount + 2);

    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.getByText(/USD/)).toBeVisible();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('button', { name: 'Save and start the first run' }).click();

    await expect(page.getByRole('alert')).toBeVisible();
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await competitorFields.nth(-1).fill('ny.example.no');
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Save and start the first run' }).click();
    await expect(page).toHaveURL(/\/brands\/\d+$/);
    await expect(page.getByRole('heading', { name: 'Browser Wizard AS' })).toBeVisible();
    const saved = JSON.parse(
        execFileSync('php', ['tests/Browser/verify-wizard.php'], {
            env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
            encoding: 'utf8',
        }),
    );
    expect(saved.core).toBeGreaterThan(0);
    expect(saved.queued).toBe(saved.due);
    expect(saved.jobs).toBe(saved.due);
    expect(saved.competitors).toBe(3);

    await page.getByRole('link', { name: 'Add market', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'New market', exact: true })).toBeVisible();
    await expect(page.getByLabel('Name', { exact: true })).toHaveCount(0);
    await page.getByLabel('Country').fill('SE');
    await page.getByLabel('Language').fill('sv');
    await page.getByRole('button', { name: 'Analyse the site' }).click();
    await expect(page.getByRole('heading', { name: 'Prompts', exact: true })).toBeVisible();
    await expect(page.getByLabel('Country', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Language', { exact: true })).toBeDisabled();
    await expect(page.getByRole('heading', { name: 'Competitors', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Country', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Language', { exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Save and start the first run' }).click();
    await expect(page).toHaveURL(/\/brands\/\d+$/);
    const markets = JSON.parse(
        execFileSync('php', ['tests/Browser/verify-wizard.php'], {
            env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
            encoding: 'utf8',
        }),
    );
    expect(markets.markets).toBe(2);
    expect(markets.swedish_queued).toBeGreaterThan(0);
    expect(markets.competitors).toBe(3);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('link', { name: 'Competitors', exact: true }).click();
    const remove = page.getByRole('button', { name: 'Delete Ny konkurrent', exact: true });
    page.once('dialog', (dialog) => dialog.dismiss());
    await remove.click();
    await expect(remove).toBeVisible();
    page.once('dialog', async (dialog) => {
        expect(dialog.message()).toContain('Ny konkurrent');
        await dialog.accept();
    });
    await remove.click();
    await expect(remove).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('row').filter({ hasText: 'Ny konkurrent' })).toHaveCount(0);
});
