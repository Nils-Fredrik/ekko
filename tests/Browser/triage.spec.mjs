import { expect, test } from '@playwright/test';

// U22: Setup › Competitors and brands (docs/ux-arbeidsflate.md 5.3). The fixture (triage-fixture.php) has 50
// synthetic unknown names in three answers each and three competitors.

test.describe.configure({ mode: 'serial' });
let page;
const errors = [];

test.beforeAll(async ({ browser }) => {
    page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/login');
    await page.locator('#email').fill(process.env.TRIAGE_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
});

test.afterAll(async () => {
    await page?.close();
});

const count = (tab) => page.getByTestId(`count-${tab}`);
const badge = () => page.getByTestId('badge-setup_competitors');
const row = (position) => page.getByTestId('queue-table').locator(`[data-row-position="${position}"]`);
const number = async (locator) => Number((await locator.innerText()).replace(/\s/g, ''));

test('50 names are decided in under two minutes with the keyboard and multi-select, and the counts follow without a reload', async () => {
    await page.goto(`/brands/${process.env.TRIAGE_BRAND_ID}/setup/competitors`);
    await expect(count('pending')).toHaveText('50');
    await expect(badge()).toHaveText('50');
    await expect(page.getByTestId('entity-panel-name')).toHaveText('Synthetic Name 01');
    await expect(page.getByTestId('entity-excerpts')).toContainText('Synthetic Name 01');
    await page.evaluate(() => {
        window.__noReload = true;
    });

    // One decision and its undo update the tab and the sidebar at once.
    const started = Date.now();
    await page.keyboard.press('4');
    await expect(count('pending')).toHaveText('49');
    await expect(badge()).toHaveText('49');
    await page.getByRole('button', { name: 'Undo', exact: true }).first().click();
    await expect(count('pending')).toHaveText('50');
    await expect(badge()).toHaveText('50');
    await expect(page.getByTestId('decided')).toHaveCount(0);

    // 30 names with the keys 1, 2 and 4 (the next row opens after each decision).
    const keys = ['1', '2', '4'];
    for (let index = 0; index < 30; index++) {
        await page.keyboard.press(keys[index % 3]);
    }
    await expect(count('pending')).toHaveText('20');
    await expect(badge()).toHaveText('20');
    await expect(page.getByTestId('decided')).toHaveCount(30);

    // The undone name (row 0) is ticked with the mouse and the other 19 with x and the arrow keys, then one click on
    // «Ignore» in the action bar.
    await row(0).getByRole('checkbox').click();
    await row(31).focus();
    for (let index = 0; index < 19; index++) {
        await page.keyboard.press('x');
        await expect(row(31 + index)).toHaveAttribute('aria-selected', 'true');
        await page.keyboard.press('ArrowDown');
        if (index < 18) await expect(row(32 + index)).toBeFocused();
    }
    await expect(page.getByRole('toolbar')).toContainText('20');
    await page.getByTestId('bulk-ignored').click();
    await expect(count('pending')).toHaveText('0');
    await expect(badge()).toHaveCount(0);
    expect(Date.now() - started).toBeLessThan(120000);

    // The other tabs follow without loading the page again.
    await expect.poll(() => number(count('competitors'))).toBe(13);
    await expect.poll(() => number(count('other'))).toBe(10);
    await expect.poll(() => number(count('ignored'))).toBe(30);
    expect(await page.evaluate(() => window.__noReload === true)).toBe(true);
    expect(errors).toEqual([]);
});

test('an undo on the ignored tab sends the name back to the queue', async () => {
    await page.getByTestId('tab-ignored').click();
    await expect(page).toHaveURL(/tab=ignored/);
    await page.locator('#panel-ignored').getByTestId('restore').first().click();
    await expect(count('ignored')).toHaveText('29');
    await expect(count('pending')).toHaveText('1');
    await expect(badge()).toHaveText('1');
});

test('dragging a competitor changes its place and color, also after a reload', async () => {
    await page.goto(`/brands/${process.env.TRIAGE_BRAND_ID}/setup/competitors?tab=competitors`);
    const table = page.getByTestId('competitor-table');
    const names = () => table.locator('[data-row-position]').evaluateAll((rows) => rows.slice(0, 3).map((item) => item.innerText));
    const first = await names();
    expect(first[0]).toContain('Rival Alpha');
    const color = (index) =>
        table.locator('[data-row-position]').nth(index).getByTestId('competitor-dot').evaluate((dot) => dot.style.backgroundColor);
    const alpha = await color(0);

    await table.getByTestId('competitor-handle').nth(0).dragTo(table.locator('[data-row-position="2"]'));
    await expect.poll(async () => (await names())[2]).toContain('Rival Alpha');
    await expect.poll(() => color(2)).not.toBe(alpha);

    await page.reload();
    expect((await names())[2]).toContain('Rival Alpha');
    // The same place with the keyboard: Alt and the arrow key on the handle.
    await table.getByTestId('competitor-handle').nth(2).focus();
    await page.keyboard.press('Alt+ArrowUp');
    await expect.poll(async () => (await names())[1]).toContain('Rival Alpha');
});

test('a competitor panel saves the name, aliases and domains', async () => {
    await page.goto(`/brands/${process.env.TRIAGE_BRAND_ID}/setup/competitors?tab=competitors`);
    await page.getByTestId('competitor-table').getByRole('button', { name: 'Rival Beta', exact: true }).click();
    const panel = page.getByTestId('competitor-panel');
    await panel.getByTestId('alias-input').fill('Beta Rivals');
    await panel.getByTestId('alias-add').click();
    await expect(panel.getByTestId('competitor-aliases')).toContainText('Beta Rivals');
    await panel.getByTestId('domain-input').fill('https://www.beta-rival.example.test/about');
    await panel.getByTestId('domain-add').click();
    await expect(panel.getByTestId('competitor-domains')).toContainText('beta-rival.example.test');
    await panel.getByTestId('competitor-name').fill('Rival Beta Ltd');
    await panel.getByTestId('competitor-name').press('Enter');
    await expect(page.getByTestId('competitor-table')).toContainText('Rival Beta Ltd');
    expect(errors).toEqual([]);
});

test('«Make competitor» that fails shows the error and puts the earlier decision back', async () => {
    await page.goto(`/brands/${process.env.TRIAGE_BRAND_ID}/setup/competitors?tab=other`);
    const before = await number(count('other'));
    expect(before).toBeGreaterThan(0);
    const decisions = [];
    await page.route('**/entity-decisions', async (route) => {
        const { decision } = route.request().postDataJSON();
        decisions.push(decision);
        if (decision === 'competitor') {
            return route.fulfill({
                status: 422,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'No room.', errors: { keys: ['The plan has no room for more competitors.'] } }),
            });
        }

        return route.continue();
    });
    await page
        .locator('#panel-other')
        .getByRole('button', { name: /^Make .* a competitor$/ })
        .first()
        .click();
    await expect(page.getByTestId('setup-error')).toContainText('no room for more competitors');
    await expect.poll(() => decisions).toEqual(['competitor', 'other']);
    await page.unroute('**/entity-decisions');
    await page.reload();
    expect(await number(count('other'))).toBe(before);
});

test('«Add competitor» creates one and «Archive» removes it again', async () => {
    await page.goto(`/brands/${process.env.TRIAGE_BRAND_ID}/setup/competitors`);
    await page.getByTestId('add-competitor').click();
    await expect(page).toHaveURL(/tab=competitors/);
    await page.getByTestId('new-competitor-name').fill('Rival Delta');
    await page.getByTestId('new-competitor-submit').click();
    await expect(page.getByTestId('competitor-table')).toContainText('Rival Delta');
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId('competitor-table').getByRole('button', { name: 'Rival Delta', exact: true }).click();
    await page.getByTestId('competitor-archive').click();
    await expect(page.getByTestId('competitor-table')).not.toContainText('Rival Delta');
    expect(errors).toEqual([]);
});
