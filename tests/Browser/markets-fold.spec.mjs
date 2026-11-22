import { expect, test } from '@playwright/test';

test.use({ viewport: { width: 1440, height: 900 } });

test('two markets and six engines fit at 1440×900, and the estimate updates within a second', async ({ page }) => {
    page.on('dialog', (dialog) => dialog.accept());
    await page.goto('/login');
    await page.locator('#email').fill(process.env.U24_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto(`/brands/${process.env.U24_BRAND_ID}/setup/markets`);
    const rows = page.getByTestId('market-row');
    await expect(rows).toHaveCount(2);
    const engines = page.getByTestId('engine-row');
    await expect(engines.nth(5)).toBeVisible();
    const marketBox = await rows.nth(1).boundingBox();
    const engineBox = await engines.nth(5).boundingBox();
    expect(marketBox.y + marketBox.height).toBeLessThanOrEqual(900);
    expect(engineBox.y + engineBox.height).toBeLessThanOrEqual(900);

    const bar = page.getByTestId('estimate-answers');
    await expect(bar).toBeVisible();
    await page.getByTestId('own-choices').click();
    const before = await bar.textContent();
    await page.locator('[data-testid^="cell-chatgpt_api-"]').nth(1).click();
    await page.getByRole('button', { name: 'Monthly', exact: true }).click();
    await expect(bar).not.toHaveText(before ?? '', { timeout: 1000 });

    await page.goto(`/brands/${process.env.U24_BRAND_ID}/setup/identity`);
    const alias = page.getByLabel('Add alias');
    await alias.fill('Markets');
    await expect(page.getByTestId('alias-preview')).toBeVisible({ timeout: 2000 });
});
