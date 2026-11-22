import { expect, test } from '@playwright/test';

// U20: Setup › Prompts and topics with the keyboard (docs/ux-arbeidsflate.md 5.2) and the plan's limit in
// the interface. The fixture (prompt-quota-fixture.php) has a plan with two active prompts per market:
// the first market is full, the second has room.

test.describe.configure({ mode: 'serial' });
let page;
const errors = [];

test.beforeAll(async ({ browser }) => {
    page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.locator('#email').fill(process.env.QUOTA_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
});

test.afterAll(async () => {
    await page?.close();
});

const setup = (market, extra = '') =>
    page.goto(`/brands/${process.env.QUOTA_BRAND_ID}/setup/prompts?market=${market}${extra}`);
const table = () => page.getByRole('grid').first();
const row = (position) => table().locator(`[data-row-position="${position}"]`);
const openId = () => new URL(page.url()).searchParams.get('open');

test('a full market disables «New prompt» and N does nothing', async () => {
    await setup(process.env.QUOTA_FULL_MARKET_ID);
    const button = page.getByRole('button', { name: 'New prompt', exact: true });
    await expect(button).toBeDisabled();
    await expect(button).toHaveAttribute('title', 'The plan is full. Deactivate or archive a prompt first.');
    await page.locator('body').press('n');
    await expect(page.getByTestId('new-prompt')).toHaveCount(0);
});

test('N opens a new row in a market with room and Escape closes it', async () => {
    await setup(process.env.QUOTA_OPEN_MARKET_ID);
    await expect(page.getByRole('button', { name: 'New prompt', exact: true })).toBeEnabled();
    await page.locator('body').press('n');
    await expect(page.getByTestId('new-prompt')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('new-prompt')).toHaveCount(0);
});

test('x selects, Enter opens and j and k move the open prompt', async () => {
    await setup(process.env.QUOTA_FULL_MARKET_ID);
    await row(0).getByRole('checkbox').click();
    await expect(row(0)).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('x');
    await expect(row(0)).toHaveAttribute('aria-selected', 'false');

    await page.keyboard.press('Enter');
    await expect.poll(openId).not.toBeNull();
    const first = openId();
    const panel = page.locator('aside[data-detail-panel]');
    await expect(panel).toBeVisible();
    await panel.getByRole('tab').first().focus();
    await page.keyboard.press('j');
    await expect.poll(openId).not.toBe(first);
    await page.keyboard.press('k');
    await expect.poll(openId).toBe(first);
    expect(errors).toEqual([]);
});

test('a preview of file A cannot import file B chosen while it loads', async () => {
    await setup(process.env.QUOTA_OPEN_MARKET_ID);
    await page.getByTestId('import-csv').click();
    const choose = (name, text) =>
        page.getByTestId('import-file').setInputFiles({
            name,
            mimeType: 'text/csv',
            buffer: Buffer.from(`text,market\n${text},SE\n`),
        });
    await choose('a.csv', 'File A prompt?');
    await expect(page.locator('textarea')).toHaveValue(/File A prompt/);

    let release;
    const held = new Promise((resolve) => (release = resolve));
    await page.route('**/prompts/import/preview', async (route) => {
        await held;
        await route.continue();
    });
    await page.getByTestId('preview-import').click();
    await choose('b.csv', 'File B prompt?');
    await expect(page.locator('textarea')).toHaveValue(/File B prompt/);
    const answered = page.waitForResponse('**/prompts/import/preview');
    release();
    await answered;
    await expect(page.getByTestId('confirm-import')).toHaveCount(0);
    await expect(page.getByText('File A prompt?')).toHaveCount(0);
    await page.unroute('**/prompts/import/preview');

    await page.getByTestId('preview-import').click();
    await expect(page.getByTestId('confirm-import')).toBeVisible();
    await expect(page.getByText('File B prompt?', { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});
