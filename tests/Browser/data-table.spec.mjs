import { test, expect } from '@playwright/test';

// U18: DataTable keyboard and selection in a real browser (docs/ux-arbeidsflate.md 4.3). The rules
// themselves are tested in tests/js/data-table.test.mjs; this checks the wiring to the DOM, such as
// the keyboard following a click inside a row.

test.describe.configure({ mode: 'serial' });
let page;
const errors = [];

test.beforeAll(async ({ browser }) => {
    page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.locator('#email').fill(process.env.SMOKE_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
});

test.afterAll(async () => {
    await page?.close();
});

const table = () => page.getByRole('grid').first();
const row = (position) => table().locator(`[data-row-position="${position}"]`);

test('shift-click selects a range and the bar counts it', async () => {
    await page.goto('/dev/components');
    await row(1).getByRole('checkbox').click();
    await row(4).getByRole('checkbox').click({ modifiers: ['Shift'] });
    await expect(table().locator('[aria-selected="true"]')).toHaveCount(4);
    await expect(page.getByRole('toolbar')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(table().locator('[aria-selected="true"]')).toHaveCount(0);
});

test('the keyboard follows a click inside a row', async () => {
    await page.goto('/dev/components');
    await row(5).getByRole('checkbox').click();
    await expect(row(5)).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('x');
    await expect(row(5)).toHaveAttribute('aria-selected', 'false');
    await expect(row(0)).toHaveAttribute('aria-selected', 'false');

    // Enter after clicking the primary cell opens that row, and the arrows move on from it.
    await row(8).getByRole('button').first().click();
    const opened = new URL(page.url()).searchParams.get('open');
    await page.keyboard.press('Enter');
    await expect.poll(() => new URL(page.url()).searchParams.get('open')).toBe(opened);
    await page.keyboard.press('ArrowDown');
    await expect(row(9)).toBeFocused();
});

test('j and k move the open row from the panel without taking the focus', async () => {
    await page.goto('/dev/components?open=3');
    const panel = page.locator('aside[data-detail-panel]');
    await panel.getByRole('tab').first().focus();
    await page.keyboard.press('j');
    await expect.poll(() => new URL(page.url()).searchParams.get('open')).not.toBe('3');
    await expect(panel.getByRole('tab').first()).toBeFocused();
    expect(errors).toEqual([]);
});
