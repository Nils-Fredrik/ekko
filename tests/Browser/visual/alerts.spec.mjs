import { expect, test } from '@playwright/test';

test.use({ storageState: '.ui-auth/owner.json' });

test('the alert form shows the scope of an integration error before submission', async ({ page }) => {
    await page.goto('/alerts');
    const form = page.locator('form').filter({ has: page.locator('option[value="integration_error"]') });
    const type = form.getByRole('combobox', { name: 'Type', exact: true });
    const brand = form.getByRole('combobox', { name: 'Brand', exact: true });
    await expect(brand).toBeVisible();
    await type.selectOption('integration_error');
    await expect(brand).toHaveCount(0);
    await expect(form.getByText('The whole tenant', { exact: true })).toBeVisible();
    await type.selectOption('visibility_drop');
    await expect(brand).toBeVisible();
});
