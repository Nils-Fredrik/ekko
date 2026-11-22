import { expect, test } from '@playwright/test';

test('alert rules explain the draft threshold and save that field', async ({ page }) => {
    await page.goto('/login');
    await page.locator('#email').fill(process.env.FILTER_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto(`/brands/${process.env.FILTER_BRAND_ID}/setup/notifications`);
    await expect(page.getByRole('heading', { name: 'Alert rules' })).toBeVisible();
    await expect(page.getByText(/Applies to all of /).first()).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Report plans' })).toBeVisible();

    const threshold = page.getByRole('textbox', { name: 'Threshold' }).first();
    const saved = page.waitForResponse(
        (response) => response.request().method() === 'PUT' && response.url().includes('/alert-rules/'),
    );
    await threshold.fill('15');
    await threshold.press('Enter');
    expect((await saved).status()).toBeLessThan(400);
    await expect(page.getByText(/more than 15 percentage points/)).toBeVisible();
    await page.reload();
    await expect(page.getByRole('textbox', { name: 'Threshold' }).first()).toHaveValue(/15/);
});
