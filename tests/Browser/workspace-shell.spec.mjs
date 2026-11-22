import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { criticalViolations } from './visual/accessibility.mjs';

// U17: the workspace sidebar (docs/ux-arbeidsflate.md 4.1). The visual suite never opens the drawer, so
// this spec checks it with axe, together with the keyboard and the market in the address.

const brand = () => process.env.SMOKE_BRAND_ID;

async function login(page) {
    await page.goto('/login');
    await page.locator('#email').fill(process.env.SMOKE_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

test('the sidebar marks the page, works with the keyboard and opens the palette', async ({ page }) => {
    await login(page);
    await page.goto(`/brands/${brand()}/sources`);
    const sidebar = page.getByTestId('workspace-sidebar');
    await expect(sidebar.getByRole('link', { name: 'Sources', exact: true })).toHaveAttribute('aria-current', 'page');

    await sidebar.getByTestId('scope-brand').focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menu')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu')).toHaveCount(0);
    await expect(sidebar.getByTestId('scope-brand')).toBeFocused();

    // The palette's input is the visible part of its dialog.
    await page.keyboard.press('ControlOrMeta+k');
    await expect(page.getByRole('combobox')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('combobox')).toHaveCount(0);
});

test('the drawer on a phone has no critical accessibility findings and closes after navigation', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/brands/${brand()}`);
    await expect(page.getByTestId('workspace-sidebar')).toBeHidden();

    await page.getByTestId('open-menu').click();
    const drawer = page.getByRole('dialog');
    await expect(drawer.getByTestId('workspace-sidebar')).toBeVisible();
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(criticalViolations(scan)).toEqual([]);
    const ids = await page.evaluate(() => [...document.querySelectorAll('[id]')].map((element) => element.id));
    expect(ids.filter((id, index) => ids.indexOf(id) !== index)).toEqual([]);

    // The link name includes the count of new actions.
    await drawer.getByRole('link', { name: /^Actions\b/ }).click();
    await expect(page).toHaveURL(new RegExp(`/brands/${brand()}/actions$`));
    await expect(page.getByRole('dialog')).toHaveCount(0);
});
