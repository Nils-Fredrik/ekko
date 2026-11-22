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
    const interval = page.getByRole('combobox', { name: /Interval for/, disabled: false });
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
    await expect(page.getByText(/Expected engine answers per month/).first()).toBeVisible();
    await expect(page.getByText(/Assumed web searches per answer/).first()).toBeVisible();
    await expect(page.getByText(/USD/).first()).toBeVisible();
    const continuePlan = page.getByRole('button', { name: 'Next' });
    await expect(continuePlan).toBeEnabled();
    await continuePlan.click();
    const startRun = page.getByRole('button', { name: 'Save and start the first run' });
    await expect(startRun).toBeEnabled();
    await startRun.click();

    await expect(page.getByRole('alert')).toBeVisible();
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await page.getByRole('button', { name: 'Back', exact: true }).click();
    await competitorFields.nth(-1).fill('ny.example.no');
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    const enginesNext = page.getByRole('button', { name: 'Next', exact: true });
    await expect(enginesNext).toBeEnabled();
    await enginesNext.click();
    await expect(startRun).toBeEnabled();
    await startRun.click();
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
        const market = route.request().postDataJSON().new_market;
        if (market.plan_mode === 'inherit') {
            expect(market.engines).toBeUndefined();
        } else {
            expect(market.plan_mode).toBe('own');
            expect(Array.isArray(market.engines)).toBe(true);
        }
        expectedMarketCost = estimate.markets.find((item) => item.id === null).total_cost_usd;
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
    await expect(page.getByRole('radio', { name: 'Use the brand plan' })).toBeChecked();
    await page.getByRole('radio', { name: 'Own choices' }).check();
    const ownInterval = page.getByRole('combobox', {
        name: 'Interval for ChatGPT',
        exact: true,
        disabled: false,
    });
    await ownInterval.selectOption('monthly');
    await expect(ownInterval).toHaveValue('monthly');
    await page.getByRole('radio', { name: 'Use the brand plan' }).check();
    await page.getByRole('radio', { name: 'Own choices' }).check();
    await expect(ownInterval).toHaveValue('weekly');
    await page.getByRole('radio', { name: 'Use the brand plan' }).check();
    await expect(page.getByRole('button', { name: 'Next', exact: true })).toBeEnabled();
    const preview = page.getByRole('heading', { name: 'Preview' }).locator('..');
    expectedMarketCost = (
        await preview
            .getByText(/^Total: .+ USD$/)
            .first()
            .innerText()
    )
        .replace('Total: ', '')
        .replace(' USD', '');
    await expect(preview.getByText(`Total: ${expectedMarketCost} USD`).first()).toBeVisible();
    await expect(preview.getByText(/Collection:/).first()).toBeVisible();
    await expect(preview.getByText(/Extraction:/).first()).toBeVisible();
    await expect(page.getByText('Unpriced existing market')).toHaveCount(0);
    const stepped = page.waitForRequest('**/measurement-plan/estimate', { timeout: 1000 }).then(
        () => true,
        () => false,
    );
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    expect(await stepped).toBe(false);
    await expect(page.getByText(`Estimated monthly cost for this market is ${expectedMarketCost} USD.`)).toBeVisible();
    const saveMarket = page.getByRole('button', { name: 'Save and start the first run' });
    await expect(saveMarket).toBeEnabled();
    await saveMarket.click();
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
    const brandPath = new URL(page.url()).pathname;
    await page.unroute('**/measurement-plan/estimate');

    await page.goto(`${brandPath}/setup/markets`);
    await expect(page.getByRole('heading', { name: 'Markets and measurement plan' })).toBeVisible();
    await expect(page.getByTestId('plan-column')).toHaveCount(2);
    const norway = page.getByRole('columnheader', { name: /^NO\b/ });
    const sweden = page.getByRole('columnheader', { name: /^SE\b/ });
    await expect(norway.getByRole('button', { name: 'Inherits', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(sweden.getByRole('button', { name: 'Inherits', exact: true })).toHaveAttribute('aria-pressed', 'true');
    const chatgpt = page
        .getByRole('row')
        .filter({ has: page.getByRole('rowheader', { name: 'ChatGPT', exact: true }) });
    const chatgptApi = page.getByRole('row').filter({
        has: page.getByRole('rowheader', { name: /^ChatGPT API\b/ }),
    });
    await expect(chatgptApi.getByRole('button').first()).toContainText('Not in the package');
    await expect(chatgptApi.getByRole('button').first()).toBeDisabled();
    await chatgpt.getByRole('button').first().click();
    await page.getByRole('button', { name: 'Monthly', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save plan', exact: true })).toBeEnabled();

    const held = [];
    await page.route('**/measurement-plan/estimate', async (route) => {
        await new Promise((resolve) => held.push(resolve));
        await route.continue();
    });
    await page.getByRole('button', { name: 'Weekly', exact: true }).click();
    await expect.poll(() => held.length, { timeout: 10000 }).toBe(1);
    await page.getByRole('button', { name: 'Every 14 days', exact: true }).click();
    await expect.poll(() => held.length, { timeout: 10000 }).toBe(2);
    held.shift()();
    await expect(page.getByText('Updating the preview…')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save plan', exact: true })).toBeDisabled();
    held.shift()();
    await expect(page.getByRole('button', { name: 'Save plan', exact: true })).toBeEnabled();
    await expect(chatgpt.getByRole('button').first()).toContainText('Every 14 days');
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
    await page.getByRole('button', { name: 'Monthly', exact: true }).click();
    await expect(
        page.getByText('A selected engine has no price. The plan cannot be saved until the price is set.'),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save plan', exact: true })).toBeDisabled();
    await page.unroute('**/measurement-plan/estimate');
    await page.getByRole('button', { name: 'Every 14 days', exact: true }).click();
    const rejectedSave = page.getByRole('button', { name: 'Save plan', exact: true });
    await expect(rejectedSave).toBeEnabled();

    await page.route(/\/measurement-plan$/, async (route) => {
        if (route.request().method() !== 'PUT') {
            await route.continue();
            return;
        }
        const body = route.request().postDataJSON();
        const rule = body.standard.engines.find((row) => row.frequency === 'every_14_days');
        rule.frequency = 'daily';
        await route.continue({ postData: JSON.stringify(body) });
    });
    await rejectedSave.click();
    await expect(page.getByText('The interval for ChatGPT is more frequent than the package allows.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Every 14 days', exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await page.unroute(/\/measurement-plan$/);

    await sweden.getByRole('button', { name: 'Own choices', exact: true }).click();
    await chatgpt.getByRole('button').nth(2).click();
    await page.getByRole('button', { name: 'Monthly', exact: true }).click();
    const savePlan = page.getByRole('button', { name: 'Save plan', exact: true });
    await expect(savePlan).toBeEnabled();
    await Promise.all([
        page.waitForResponse(
            (response) => response.url().includes('/measurement-plan') && response.request().method() === 'PUT',
        ),
        savePlan.click(),
    ]);
    await page.reload();
    await expect(sweden.getByRole('button', { name: 'Own choices', exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await expect(chatgpt.getByRole('button').nth(2)).toContainText('Monthly');
    await sweden.getByRole('button', { name: 'Inherits', exact: true }).click();
    await sweden.getByRole('button', { name: 'Own choices', exact: true }).click();
    await expect(chatgpt.getByRole('button').nth(2)).toContainText('Monthly');
    await sweden.getByRole('button', { name: 'Inherits', exact: true }).click();
    await expect(savePlan).toBeEnabled();
    await Promise.all([
        page.waitForResponse(
            (response) => response.url().includes('/measurement-plan') && response.request().method() === 'PUT',
        ),
        savePlan.click(),
    ]);
    await page.reload();
    await expect(sweden.getByRole('button', { name: 'Inherits', exact: true })).toHaveAttribute('aria-pressed', 'true');

    await chatgpt.getByRole('button').first().click();
    await page.getByRole('button', { name: 'Weekly', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save plan', exact: true })).toBeEnabled();
    const swedenMarket = page.getByRole('button', { name: /^SE\b/ });
    await swedenMarket.focus();
    await expect(swedenMarket).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { level: 2, name: 'SE', exact: true })).toBeVisible();
    const refreshedEstimate = page.waitForResponse(
        (response) =>
            response.url().includes('/measurement-plan/estimate') &&
            response.request().method() === 'POST' &&
            response.ok(),
    );
    await page.getByTestId('market-active').uncheck();
    await refreshedEstimate;
    await expect(page.getByTestId('market-row').filter({ hasText: 'sv' })).toContainText('Inactive');
    await expect(page.getByTestId('market-row').filter({ hasText: 'nb' })).toContainText('Active');
    await expect(page.getByTestId('plan-column')).toHaveCount(1);
    await expect(chatgpt.getByRole('button').first()).toContainText('Weekly');
    await expect(page.getByRole('button', { name: 'Save plan', exact: true })).toBeEnabled();

    await page.goto(brandPath);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByTestId('open-sidebar').click();
    await page.getByTestId('sidebar-drawer').getByRole('link', { name: 'Competitors', exact: true }).click();
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

    await page.setViewportSize({ width: 1280, height: 800 });
    await page.context().clearCookies();
    await page.goto('/login');
    await page.locator('#email').fill(process.env.SMOKE_WIZARD_READER_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    let readerEstimates = 0;
    const countReaderEstimate = (request) => {
        if (request.url().includes('/measurement-plan/estimate')) {
            readerEstimates += 1;
        }
    };
    page.on('request', countReaderEstimate);
    await page.goto(`${brandPath}/setup/markets`);
    await expect(page.getByText('You can view the measurement plan, but not change it.')).toBeVisible();
    await expect(chatgpt.getByRole('button').first()).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Own choices', exact: true }).first()).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Save plan', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Preview' })).toHaveCount(0);
    await page.waitForTimeout(1000);
    expect(readerEstimates).toBe(0);
    page.off('request', countReaderEstimate);
});
