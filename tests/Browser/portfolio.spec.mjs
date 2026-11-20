import { test, expect } from '@playwright/test';

// U10: the partner portfolio is sorted by the largest drop first, can be re-sorted, and a row opens the client's
// overview, where «What has happened» shows alerts, model changes, completed actions and new competitors together.
test('the partner portfolio sorts by drop and a row opens the client overview with its timeline', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.name));
    await page.goto('/login');
    await page.getByRole('button', { name: 'English', exact: true }).click();
    await page.locator('#email').fill(process.env.SMOKE_PORTFOLIO_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await page.getByRole('button', { name: 'Use a recovery code', exact: true }).click();
    await page.locator('#recovery_code').fill('recovery-code-1');
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto('/partner');
    const rows = page.getByTestId('portfolio-row');
    await expect(rows).toHaveCount(4);
    // The figures arrive as one deferred prop; the change column fills in when they do.
    await expect(rows.first().getByTestId('portfolio-change')).toContainText('pp');
    const name = rows.locator('th[scope="row"] > button:first-child');
    await expect(name).toHaveText(['Portfolio Falling', 'Portfolio Steady', 'Portfolio Rising', 'Portfolio Empty']);

    const falling = rows.first();
    await expect(falling.getByTestId('portfolio-change')).toContainText(/[-−]60 pp/);
    await expect(falling.getByTestId('portfolio-change')).toContainText('down 60 percentage points');
    await expect(falling.getByTestId('portfolio-alerts')).toHaveText('1 open');
    await expect(rows.nth(1).getByTestId('portfolio-alerts')).toHaveText('None');
    await expect(rows.last().getByTestId('portfolio-change')).toContainText('No comparison');

    // Re-sort by change: the largest rise first.
    await page.getByRole('button', { name: 'Sort by Change' }).click();
    await expect(page.locator('th[aria-sort="descending"]')).toContainText('Change');
    await expect(name.first()).toHaveText('Portfolio Rising');
    await page.getByRole('button', { name: 'Sort by Client' }).click();
    await expect(name.first()).toHaveText('Portfolio Empty');

    // A click on the row opens the client's overview.
    await page
        .locator('tr[data-testid="portfolio-row"]', { hasText: 'Portfolio Falling' })
        .locator('td')
        .first()
        .click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByTestId('brand-card')).toContainText('Portfolio Falling brand');
    await expect(page.getByTestId('brand-actions')).toHaveText('Open actions: 1');
    const timeline = page.getByTestId('timeline');
    await expect(timeline.getByRole('heading', { name: 'What has happened' })).toBeVisible();
    await expect(timeline.locator('li')).toHaveCount(4);
    await expect(timeline.locator('li[data-kind="alert"]')).toContainText('Alert');
    await expect(timeline.locator('li[data-kind="action_done"]')).toContainText('Finished portfolio action');
    await expect(timeline.locator('li[data-kind="competitor"]')).toContainText('Fresh competitor');
    await expect(timeline.locator('li[data-kind="model_change"]')).toContainText(
        'portfolio-model-1 → portfolio-model-2',
    );
    // Newest first: alert (yesterday), action (two days ago), competitor (four), model change (five).
    expect(await timeline.locator('li').evaluateAll((items) => items.map((item) => item.dataset.kind))).toEqual([
        'alert',
        'action_done',
        'competitor',
        'model_change',
    ]);
    expect(errors).toEqual([]);
});
