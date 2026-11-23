import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { databaseEnv } from './database.mjs';

test('acknowledge removes an inbox row without leaving the page, and undo brings it back', async ({ page }) => {
    execFileSync('php', ['tests/Browser/seed-inbox.php'], {
        env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
    });
    await page.goto('/login');
    await page.locator('#email').fill('browser-inbox@example.test');
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto('/inbox');
    const row = page.getByTestId('inbox-row-alert').filter({ has: page.getByRole('heading', { name: 'Usage reached 80% of the quota or budget', exact: true }) }).first();
    await expect(row).toBeVisible();
    const all = page.getByTestId('inbox-count-all');
    const badge = page.getByTestId('badge-inbox');
    await expect(badge).toHaveText(await all.innerText());
    const before = await all.innerText();

    await row.getByRole('button', { name: 'Acknowledge', exact: true }).click();
    await expect(page).toHaveURL(/\/inbox/);
    await expect(row).toHaveCount(0);
    await expect(all).toHaveText('0');
    await expect(badge).toHaveCount(0);

    await page.getByRole('button', { name: 'Undo', exact: true }).click();
    await expect(row).toBeVisible();
    await expect(all).toHaveText(before);
    await expect(badge).toHaveText(before);
});

test('inbox undo restores planned and declined actions and a contradicted claim', async ({ page }) => {
    execFileSync('php', ['tests/Browser/seed-inbox.php', 'actions'], {
        env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
    });
    await page.goto('/login');
    await page.locator('#email').fill('browser-inbox-actions@example.test');
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/inbox');

    const action = page.getByTestId('inbox-row-action').filter({ hasText: 'Review the new source' });
    const claim = page.getByTestId('inbox-row-claim');
    const undo = page.getByRole('button', { name: 'Undo', exact: true });
    await expect(action).toContainText(/Inbox fixture · Norway · .+\d/);
    await expect(claim).toContainText(/1 answer · 1 engine · Inbox fixture · Norway/);
    for (const choice of ['plan', 'decline']) {
        await action.getByTestId(`inbox-${choice}`).click();
        await expect(action).toHaveCount(0);
        await expect(page.getByTestId('badge-inbox')).toHaveText('1');
        await undo.click();
        await expect(action).toBeVisible();
        await expect(page.getByTestId('badge-inbox')).toHaveText('2');
    }
    await claim.getByTestId('inbox-create_action').click();
    await expect(claim).toHaveCount(0);
    await expect(page.getByTestId('inbox-row-action')).toHaveCount(2);
    await undo.click();
    await expect(claim).toBeVisible();
    await expect(page.getByTestId('inbox-row-action')).toHaveCount(1);
    await page.reload();
    await expect(claim).toBeVisible();
});
