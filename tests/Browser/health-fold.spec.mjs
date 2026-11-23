import { expect, test } from '@playwright/test';

test.use({ viewport: { width: 1440, height: 900 } });

test('the health header stays above the key figures, and the checklist can be hidden', async ({ page }) => {
    await page.goto('/login');
    await page.locator('#email').fill(process.env.FILTER_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto(`/brands/${process.env.FILTER_BRAND_ID}`);
    const header = page.getByTestId('health-header');
    const kpis = page.getByTestId('primary-kpis');
    await expect(header).toBeVisible();
    await expect(kpis).toBeVisible();
    const box = await kpis.boundingBox();
    expect(box.y + box.height).toBeLessThanOrEqual(900);

    await page.getByTestId('hide-checklist').click();
    await expect(page.getByRole('heading', { name: 'Get started' })).toHaveCount(0);
    await expect(kpis).toBeVisible();

    const report = page.getByTestId('workspace-header').getByRole('link', { name: 'Create report now' });
    await report.click();
    await expect(page).toHaveURL(new RegExp(`/brands/${process.env.FILTER_BRAND_ID}/setup/notifications$`));
});
