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
    const grid = page.getByRole('grid', { name: 'Markets and measurement plan' });
    const rows = grid.locator('[data-row-position]');
    await expect(page.locator('[data-detail-panel]')).toHaveCount(0);
    await expect(rows).toHaveCount(2);
    await expect(page.getByTestId('plan-column')).toHaveCount(2);
    const engines = page.getByTestId('engine-row');
    await expect(engines.nth(5)).toBeVisible();
    const marketBox = await rows.nth(1).boundingBox();
    const engineBox = await engines.nth(5).boundingBox();
    expect(marketBox.y + marketBox.height).toBeLessThanOrEqual(900);
    expect(engineBox.y + engineBox.height).toBeLessThanOrEqual(900);

    const bar = page.getByTestId('estimate-answers');
    await expect(bar).toBeVisible();
    await page.getByTestId('own-choices').first().click();
    const before = await bar.textContent();
    await page.locator('[data-testid^="cell-chatgpt_api-"]').nth(1).click();
    await page.getByRole('button', { name: 'Monthly', exact: true }).click();
    await expect(bar).not.toHaveText(before ?? '', { timeout: 1000 });
    await rows.first().getByRole('button').click();
    await expect(page).toHaveURL(/[?&]open=\d+/);
    await page.getByTestId('market-active').click();
    await expect(page.getByRole('button', { name: 'Save plan', exact: true })).toBeVisible();

    await page.goto(`/brands/${process.env.U24_BRAND_ID}/setup/identity`);
    const alias = page.getByLabel('Add alias');
    await alias.fill('Markets');
    await expect(page.getByTestId('alias-preview')).toBeVisible({ timeout: 2000 });
});

test('the market drawer uses the URL, shows localized labels and rolls back an invalid location', async ({ page }) => {
    await page.goto('/login');
    await page.locator('#email').fill(process.env.U24_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/dashboard$/);
    await page.goto(`/brands/${process.env.U24_BRAND_ID}/setup/markets`);
    const row = page.getByRole('row').filter({ has: page.getByRole('button', { name: /Sweden.*Swedish/ }) });
    await expect(row).toBeVisible();
    await expect(page.getByTestId('plan-column').filter({ hasText: 'Sweden' })).toBeVisible();
    await row.press('Enter');
    await expect(page).toHaveURL(/[?&]open=\d+/);
    await page.reload();
    const panel = page.locator('[data-detail-panel]');
    await expect(panel.getByRole('heading', { name: 'Sweden' })).toBeVisible();
    const location = panel.getByRole('textbox');
    await location.fill('Stockholm');
    await location.press('Tab');
    await expect(panel.getByText('Saved', { exact: true })).toBeVisible();
    await expect(panel.getByRole('heading', { name: 'Stockholm' })).toBeVisible();
    await expect(page.getByTestId('plan-column').filter({ hasText: 'Stockholm' })).toBeVisible();
    await location.fill('X'.repeat(256));
    await location.press('Tab');
    await expect(location).toHaveValue('Stockholm');
    await expect(panel.locator('[aria-live="polite"]')).toContainText('255');
    await location.press('Escape');
    await expect(panel).toHaveCount(0);
    await expect(page).not.toHaveURL(/[?&]open=/);
    // Closing while a save is in flight must leave the panel closed after the server responds.
    await page.getByRole('button', { name: /Stockholm.*Swedish/ }).click();
    const pending = page.waitForRequest((request) => request.method() === 'PATCH');
    let resume;
    const paused = new Promise((resolve) => {
        resume = resolve;
    });
    await page.route('**/markets/*', async (route) => {
        if (route.request().method() === 'PATCH') {
            await paused;
        }
        await route.continue();
    });
    await location.fill('');
    await location.press('Enter');
    await pending;
    await location.press('Escape');
    resume();
    await expect(row).toBeVisible();
    await expect(panel).toHaveCount(0);
    await expect(page).not.toHaveURL(/[?&]open=/);
});
