import { expect, test } from '@playwright/test';

test.use({ storageState: '.ui-auth/owner.json' });

test('a tenant-wide alert says it covers the whole tenant', async ({ page }) => {
    await page.goto(`/brands/${process.env.SMOKE_BRAND_ID}/setup/notifications`);
    await expect(page.getByRole('heading', { name: 'Alert rules' })).toBeVisible();
    await expect(page.getByText(/Applies to all of /).first()).toBeVisible();
});
