import { test, expect } from '@playwright/test';

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
    await page.getByLabel('Country').fill('NO');
    await page.getByLabel('Language').fill('nb');
    await page.getByRole('button', { name: 'Analyse the site' }).click();

    await expect(page.getByRole('heading', { name: 'Competitors' })).toBeVisible();
    const competitors = page.getByRole('heading', { name: 'Competitors' }).locator('..');
    await competitors.getByRole('button', { name: 'Add' }).click();
    const competitorFields = competitors.locator('input');
    await competitorFields.nth(-2).fill('Ny konkurrent');
    await competitorFields.nth(-1).fill('ny.example.no');

    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.getByText(/USD/)).toBeVisible();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('button', { name: 'Save and start the first run' }).click();

    await expect(page).toHaveURL(/\/brands\/\d+$/);
    await expect(page.getByRole('heading', { name: 'Browser Wizard AS' })).toBeVisible();
});
