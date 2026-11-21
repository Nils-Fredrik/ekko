import { expect, test } from '@playwright/test';

test('prompt import shows changes when every row already exists', async ({ page }) => {
    await page.goto('/login');
    await page.locator('#email').fill(process.env.FILTER_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto(`/brands/${process.env.FILTER_BRAND_ID}/prompts`);

    const file = page.locator('input[type="file"]');
    const upload = (priority) => file.setInputFiles({
        name: 'prompts.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`text,market,priority,active\nCSV receipt fixture?,NO,${priority},0\n`),
    });

    await upload('standard');
    await expect(page.getByText('Created 1. Updated 0. Skipped 0.', { exact: true })).toBeVisible();
    await upload('core');
    await expect(page.getByText('Created 0. Updated 1. Skipped 0.', { exact: true })).toBeVisible();
});
