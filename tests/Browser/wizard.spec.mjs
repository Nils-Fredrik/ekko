import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { databaseEnv } from './database.mjs';

test('the wizard confirms a proposal and opens the brand after the first run', async ({ page }) => {
    test.setTimeout(120000);
    await page.goto('/login');
    await page.locator('#email').fill(process.env.SMOKE_WIZARD_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto('/onboarding/wizard');
    await page.getByLabel('Name').fill('Browser Wizard AS');
    await page.getByLabel('Domain').fill('eksempel.no');
    await page.getByLabel('Country').fill('1');
    await page.getByLabel('Language').fill('nb');
    await page.getByRole('button', { name: 'Analyse the site' }).click();

    await expect(page.getByText(/country.*invalid/i)).toBeVisible();
    await page.getByLabel('Country').fill('NO');
    await page.getByRole('button', { name: 'Analyse the site' }).click();
    await expect(page.getByRole('heading', { name: 'Competitors' })).toBeVisible();
    const competitors = page.getByRole('heading', { name: 'Competitors' }).locator('..');
    await page.getByRole('button', { name: 'Add competitor', exact: true }).click();
    const competitorFields = competitors.locator('input');
    await competitorFields.nth(-2).fill('Ny konkurrent');
    await competitorFields.nth(-1).fill('invalid-domain');
    const topics = page.getByRole('heading', { name: 'Topics', exact: true }).locator('..');
    const topicCount = await topics.locator('input').count();
    await page.getByRole('button', { name: 'Add topic', exact: true }).click();
    await topics.locator('input').last().fill('Trail running');
    await page.getByRole('button', { name: 'Add topic', exact: true }).click();
    await topics.locator('input').last().fill('Road running');
    await topics.getByRole('button', { name: 'Remove', exact: true }).nth(topicCount).click();
    await page.getByRole('button', { name: 'Add topic', exact: true }).click();
    await topics.locator('input').last().fill('Walking');
    await expect(topics.locator('input')).toHaveCount(topicCount + 2);

    await page.getByRole('button', { name: 'Next' }).click();
    const interval = page.getByRole('combobox', { name: /Interval for/ }).first();
    await expect(interval.locator('option')).toHaveText([
        'Off',
        'Monthly',
        'Every 3 weeks',
        'Every 14 days',
        'Weekly',
        'Daily',
    ]);
    await expect(page.getByRole('combobox', { name: /Prompts for/ }).first()).toBeVisible();
    await expect(page.getByRole('combobox', { name: /Repetitions for/ }).first()).toBeVisible();
    await interval.selectOption('weekly');
    await expect(page.getByRole('combobox', { name: /Core frequency for/ }).first()).toBeVisible();
    await expect(page.getByText(/Expected engine answers per month/)).toBeVisible();
    await expect(page.getByText(/Assumed web searches per answer/)).toBeVisible();
    await expect(page.getByText(/USD/).first()).toBeVisible();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('button', { name: 'Save and start the first run' }).click();

    await expect(page.getByRole('alert')).toBeVisible();
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await competitorFields.nth(-1).fill('ny.example.no');
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Save and start the first run' }).click();
    await expect(page).toHaveURL(/\/brands\/\d+$/);
    await expect(page.getByRole('heading', { name: 'Browser Wizard AS' })).toBeVisible();
    const saved = JSON.parse(
        execFileSync('php', ['tests/Browser/verify-wizard.php'], {
            env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
            encoding: 'utf8',
        }),
    );
    expect(saved.core).toBeGreaterThan(0);
    expect(saved.queued).toBe(saved.due);
    expect(saved.jobs).toBe(saved.due);
    expect(saved.competitors).toBe(3);

    // Keep the real wizard request and new-market price, while simulating an unrelated unpriced saved market.
    let expectedMarketCost;
    await page.route('**/measurement-plan/estimate', async (route) => {
        const response = await route.fetch();
        const estimate = await response.json();
        expect(route.request().postDataJSON().new_market.plan_mode).toBe('inherit');
        expect(route.request().postDataJSON().new_market.engines).toBeUndefined();
        expectedMarketCost = estimate.markets.find((market) => market.id === null).total_cost_usd;
        const existing = estimate.markets.find((market) => market.id !== null);
        existing.total_cost_usd = null;
        estimate.total_cost_usd = null;
        estimate.issues.push({ market_id: existing.id, code: 'no_price', message: 'Unpriced existing market' });
        await route.fulfill({ response, json: estimate });
    });
    await page.getByRole('link', { name: 'Add market', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'New market', exact: true })).toBeVisible();
    await expect(page.getByLabel('Name', { exact: true })).toHaveCount(0);
    await page.getByLabel('Country').fill('SE');
    await page.getByLabel('Language').fill('sv');
    await page.getByRole('button', { name: 'Analyse the site' }).click();
    await expect(page.getByRole('heading', { name: 'Prompts', exact: true })).toBeVisible();
    await expect(page.getByLabel('Country', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Language', { exact: true })).toBeDisabled();
    await expect(page.getByRole('heading', { name: 'Competitors', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Country', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Language', { exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(page.getByText(/Estimated monthly cost for this market/)).toBeVisible();
    await expect(page.getByText(`Estimated monthly cost for this market is ${expectedMarketCost} USD.`)).toBeVisible();
    await expect(page.getByText('Unpriced existing market')).toHaveCount(0);
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Save and start the first run' }).click();
    await expect(page).toHaveURL(/\/brands\/\d+$/);
    const markets = JSON.parse(
        execFileSync('php', ['tests/Browser/verify-wizard.php'], {
            env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
            encoding: 'utf8',
        }),
    );
    expect(markets.markets).toBe(2);
    expect(markets.swedish_queued).toBeGreaterThan(0);
    expect(markets.competitors).toBe(3);
    await page.unroute('**/measurement-plan/estimate');

    await page.getByRole('banner').getByRole('link', { name: 'Settings', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Measurement plan' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'NO / nb' }).getByText('Inherits the brand plan')).toBeVisible();
    await expect(page.getByRole('group', { name: 'SE / sv' }).getByText('Inherits the brand plan')).toBeVisible();
    const standardInterval = page.getByRole('combobox', { name: 'Interval for ChatGPT', exact: true }).first();
    await expect(standardInterval.locator('option')).toHaveText([
        'Off',
        'Monthly',
        'Every 3 weeks',
        'Every 14 days',
        'Weekly',
        'Daily',
    ]);
    await standardInterval.focus();
    await expect(standardInterval).toBeFocused();
    await page.keyboard.press('m');
    await expect(standardInterval).toHaveValue('monthly');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('combobox', { name: 'Prompts for ChatGPT', exact: true }).first()).toBeFocused();
    await expect(page.getByRole('button', { name: 'Save measurement plan' })).toBeEnabled();

    const held = [];
    await page.route('**/measurement-plan/estimate', async (route) => {
        await new Promise((resolve) => held.push(resolve));
        await route.continue();
    });
    await standardInterval.selectOption('monthly');
    await standardInterval.selectOption('weekly');
    await standardInterval.selectOption('every_14_days');
    await expect.poll(() => held.length).toBeGreaterThanOrEqual(2);
    held.shift()();
    await expect(page.getByRole('button', { name: 'Save measurement plan' })).toBeDisabled();
    while (held.length > 0) {
        held.shift()();
    }
    await expect(page.getByRole('button', { name: 'Save measurement plan' })).toBeEnabled();
    await page.unroute('**/measurement-plan/estimate');

    await page.route('**/measurement-plan/estimate', async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        body.issues.push({
            code: 'no_price',
            engine_key: 'chatgpt_api',
            market_id: body.markets[0].id,
            message: 'Engine has no price.',
        });
        body.total_cost_usd = null;
        body.markets[0].total_cost_usd = null;
        await route.fulfill({ response, json: body });
    });
    await standardInterval.selectOption('monthly');
    await expect(
        page.getByText('A selected engine has no price. The plan cannot be saved until the price is set.'),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save measurement plan' })).toBeDisabled();
    await page.unroute('**/measurement-plan/estimate');
    await standardInterval.selectOption('weekly');
    await expect(page.getByRole('button', { name: 'Save measurement plan' })).toBeEnabled();

    const sweden = page.getByRole('group', { name: 'SE / sv' });
    await sweden.getByRole('radio', { name: 'Own choices' }).check();
    await sweden.getByRole('combobox', { name: 'Interval for ChatGPT', exact: true }).first().selectOption('monthly');
    const savePlan = page.getByRole('button', { name: 'Save measurement plan' });
    await expect(savePlan).toBeEnabled();
    await Promise.all([
        page.waitForResponse(
            (response) => response.url().includes('/measurement-plan') && response.request().method() === 'PUT',
        ),
        savePlan.click(),
    ]);
    await page.reload();
    await expect(page.getByRole('group', { name: 'SE / sv' }).getByRole('radio', { name: 'Own choices' })).toBeChecked();
    await expect(
        page.getByRole('group', { name: 'SE / sv' }).getByRole('combobox', { name: 'Interval for ChatGPT', exact: true }).first(),
    ).toHaveValue('monthly');
    await page.getByRole('group', { name: 'SE / sv' }).getByRole('radio', { name: 'Use the brand plan' }).check();
    await expect(savePlan).toBeEnabled();
    await Promise.all([
        page.waitForResponse(
            (response) => response.url().includes('/measurement-plan') && response.request().method() === 'PUT',
        ),
        savePlan.click(),
    ]);
    await page.reload();
    await expect(
        page.getByRole('group', { name: 'SE / sv' }).getByRole('radio', { name: 'Use the brand plan' }),
    ).toBeChecked();

    await page.goto(page.url().replace(/\/settings\/?$/, ''));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('link', { name: 'Competitors', exact: true }).click();
    const remove = page.getByRole('button', { name: 'Delete Ny konkurrent', exact: true });
    page.once('dialog', (dialog) => dialog.dismiss());
    await remove.click();
    await expect(remove).toBeVisible();
    page.once('dialog', async (dialog) => {
        expect(dialog.message()).toContain('Ny konkurrent');
        await dialog.accept();
    });
    await remove.click();
    await expect(remove).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('row').filter({ hasText: 'Ny konkurrent' })).toHaveCount(0);
});
