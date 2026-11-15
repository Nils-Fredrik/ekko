import { test, expect } from '@playwright/test';

test('wrong credentials cannot reach the dashboard', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('button', { name: 'English', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await page.locator('#email').fill(process.env.SMOKE_EMAIL);
    await page.locator('#password').fill('deliberately-wrong-password');
    await page.locator('button[type="submit"]').click();
    await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});

test('login renders the tenant overview and brand metrics', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.name));
    await page.goto('/login');
    await page.locator('#email').fill(process.env.SMOKE_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    const brand = page.locator(`a[href$="/brands/${process.env.SMOKE_BRAND_ID}"]`).first();
    await expect(brand).toContainText('Browser fixture brand');
    await brand.click();
    await expect(page).toHaveURL(new RegExp(`/brands/${process.env.SMOKE_BRAND_ID}$`));
    await expect(page.getByRole('heading', { name: 'Browser fixture brand' })).toBeVisible();
    expect(errors).toEqual([]);
});
