// U17: the sidebar works with the keyboard, the market follows the URL, and on mobile the sidebar is a drawer
// (docs/ux-arbeidsflate.md 4.1). Runs against the same synthetic fixture as the page register.
import process from 'node:process';
import { test, expect } from '@playwright/test';
import { observeInertia, settleCharts } from './settle.mjs';

const brandPath = (suffix = '') => `/brands/${process.env.SMOKE_BRAND_ID}${suffix}`;

test.use({ storageState: '.ui-auth/owner.json' });

async function open(page, baseURL, path) {
    await page.clock.setFixedTime(new Date('2026-10-16T12:00:00Z'));
    await page.route('**/*', (route) =>
        new URL(route.request().url()).origin === new URL(baseURL).origin ? route.continue() : route.abort(),
    );
    await observeInertia(page);
    await page.goto(path);
    await settleCharts(page, { inertia: true });
}

test('the sidebar marks the page, works with the keyboard and keeps the market in the URL', async ({
    page,
    baseURL,
}, info) => {
    test.skip(info.project.name !== 'desktop-dark', 'Desktop behaviour is checked once.');
    await open(page, baseURL, brandPath('/sources'));
    const sidebar = page.getByTestId('sidebar');

    await expect(sidebar.locator('[aria-current="page"]')).toHaveCount(1);
    await expect(sidebar.getByTestId('nav-sources')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('brand-picker')).toContainText('Fjord Coffee');
    await expect(page.getByTestId('market-picker')).toContainText('All markets');

    // The skip link comes first, then the sidebar in reading order.
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();

    // Choose the market with the keyboard: Enter opens the menu, arrows move, Enter follows the link.
    await page.getByTestId('market-picker').focus();
    await page.keyboard.press('Enter');
    const items = page.getByRole('menuitem');
    await expect(items).toHaveCount(2);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/[?&]market=\d+/);
    await settleCharts(page, { inertia: true });
    const market = new URL(page.url()).searchParams.get('market');
    await expect(page.getByTestId('market-picker')).toContainText('Norway');
    await expect(sidebar.getByTestId('nav-overview')).toHaveAttribute('href', new RegExp(`market=${market}`));
    await expect(sidebar.getByTestId('nav-actions')).not.toHaveAttribute('href', /market=/);

    // A copied URL gives the same scope.
    const copied = page.url();
    const other = await page.context().newPage();
    await open(other, baseURL, copied);
    await expect(other.getByTestId('market-picker')).toContainText('Norway');
    await other.close();

    // Escape closes a menu and gives the focus back to its button.
    await page.getByTestId('brand-picker').focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menu')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu')).toHaveCount(0);
    await expect(page.getByTestId('brand-picker')).toBeFocused();

    // Ctrl+K (⌘K on a Mac) opens the command palette, and so does the search button.
    await page.keyboard.press('Control+k');
    await expect(page.getByRole('dialog').getByRole('combobox')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByTestId('open-palette').click();
    await expect(page.getByRole('dialog').getByRole('combobox')).toBeVisible();
});

test('on mobile the sidebar is a drawer that closes on navigation', async ({ page, baseURL }, info) => {
    test.skip(info.project.name !== 'mobile-dark', 'Mobile behaviour is checked once.');
    await open(page, baseURL, brandPath());

    await expect(page.getByTestId('sidebar')).toBeHidden();
    await page.getByTestId('open-sidebar').click();
    const drawer = page.getByTestId('sidebar-drawer');
    await expect(drawer).toBeVisible();
    await expect(drawer.getByTestId('nav-overview')).toHaveAttribute('aria-current', 'page');

    await drawer.getByTestId('nav-sources').click();
    await expect(page).toHaveURL(/\/sources/);
    await expect(drawer).toBeHidden();

    await page.getByTestId('open-sidebar').click();
    await expect(page.getByTestId('sidebar-drawer')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('sidebar-drawer')).toBeHidden();
});
