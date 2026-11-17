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
    await expect(topics.locator('input')).toHaveCount(topicCount + 1);
    await topics.getByRole('button', { name: 'Remove', exact: true }).last().click();
    await expect(topics.locator('input')).toHaveCount(topicCount);

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
    expect(saved.queued).toBe(saved.core);
    expect(saved.jobs).toBe(saved.core);
    expect(saved.competitors).toBe(3);
});
