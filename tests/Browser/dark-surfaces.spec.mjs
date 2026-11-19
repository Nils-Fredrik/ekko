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
        '/user/profile',
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
        const lightSurfaces = await page.evaluate(() => {
            const banned = new Set(['rgb(255, 255, 255)', 'rgb(245, 237, 228)', 'rgb(251, 247, 241)']);
            return [...document.querySelectorAll('body *')]
                .filter((element) => banned.has(getComputedStyle(element).backgroundColor))
                .slice(0, 6)
                .map((element) => `${element.tagName}.${String(element.className).slice(0, 80)}`);
        });
        expect(lightSurfaces, path).toEqual([]);
    }

    await page.locator('button:has(span[class*="h-[34px]"])').click();
    const profile = page.getByRole('link', { name: 'Profile', exact: true });
    await expect(profile).toBeVisible();
    await expect(profile).toHaveCSS('color', 'rgb(245, 237, 228)');
});
