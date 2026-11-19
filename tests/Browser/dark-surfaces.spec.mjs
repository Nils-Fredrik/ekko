import { test, expect } from '@playwright/test';

const brand = () => process.env.SMOKE_BRAND_ID;

test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
        localStorage.setItem('ekko.theme', 'dark');
    });
});

test('dark theme has no white surfaces', async ({ page }) => {
    const paths = [
        '/login',
        '/dashboard',
        '/brands',
        `/brands/${brand()}`,
        `/brands/${brand()}/prompts`,
        `/brands/${brand()}/competitors`,
        `/brands/${brand()}/sources`,
        `/brands/${brand()}/crawlers`,
        '/alerts',
        '/reports',
    ];

    await page.goto('/login');
    await page.locator('#email').fill(process.env.SMOKE_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    for (const path of paths) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBeLessThan(400);
        await expect(page.locator('html'), `${path} ${page.url()}`).toHaveAttribute('data-theme', 'dark');
        const white = await page.evaluate(() =>
            [...document.querySelectorAll('body *')]
                .filter((element) => getComputedStyle(element).backgroundColor === 'rgb(255, 255, 255)')
                .slice(0, 6)
                .map((element) => `${element.tagName}.${String(element.className).slice(0, 80)}`),
        );
        expect(white, path).toEqual([]);
    }
});
