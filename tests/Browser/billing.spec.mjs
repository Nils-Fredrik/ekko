import { test, expect } from '@playwright/test';

test('pitch and customer lines share one accessible markup editor and update together', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('button', { name: 'English', exact: true }).click();
    await page.locator('#email').fill('browser-billing@example.test');
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await page.getByRole('button', { name: 'Use a recovery code', exact: true }).click();
    await page.locator('#recovery_code').fill('recovery-code-1');
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/admin/billing?month=2026-10');

    const lines = page.locator('tbody tr').filter({ hasText: 'Billing fixture' });
    await expect(lines).toHaveCount(2);
    const markup = page.getByRole('spinbutton', { name: 'Markup for Billing fixture', exact: true });
    await expect(markup).toHaveCount(1);
    const id = await markup.getAttribute('id');
    await expect(page.locator(`[id="${id}"]`)).toHaveCount(1);
    await expect(markup).toHaveValue('10.0000');
    await markup.fill('25');
    await markup.locator('xpath=ancestor::form').getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Saved');
    await expect(lines.nth(0).locator('td').last()).toHaveText('12.50');
    await expect(lines.nth(1).locator('td').last()).toHaveText('12.50');
    await page.reload();
    await expect(markup).toHaveCount(1);
    await expect(markup).toHaveValue('25.0000');
    await expect(lines.nth(0).locator('td').last()).toHaveText('12.50');
    await expect(lines.nth(1).locator('td').last()).toHaveText('12.50');
});
