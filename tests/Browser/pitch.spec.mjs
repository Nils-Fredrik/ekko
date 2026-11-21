import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { databaseEnv } from './database.mjs';

// T49: a partner advisor creates a pitch project, sets it up in the wizard with approved fact proposals, follows the
// collection, checks the facts and claims, reads the finished pitch report and converts the pitch. The site
// analysis uses the wizard fakes; the collection, extraction and fact check are stood in for by pitch-collect.php.
test('a new pitch project goes through the wizard to a finished pitch report and is converted', async ({ page }) => {
    test.setTimeout(90000);
    page.on('dialog', (dialog) => dialog.accept());
    await page.goto('/login');
    await page.getByRole('button', { name: 'English', exact: true }).click();
    await page.locator('#email').fill(process.env.SMOKE_ADVISOR_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await page.getByRole('button', { name: 'Use a recovery code', exact: true }).click();
    await page.locator('#recovery_code').fill('recovery-code-1');
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);

    // Create the pitch project.
    await page.getByRole('link', { name: 'Pitch', exact: true }).first().click();
    await expect(page.getByRole('heading', { name: 'Pitch projects', exact: true })).toBeVisible();
    await expect(page.getByText('No pitch projects')).toBeVisible();
    await page.getByLabel('Project name').fill('Browser Pitch AS');
    await page.getByRole('button', { name: 'Create pitch project' }).click();
    const card = page.locator('[data-pitch="Browser Pitch AS"]');
    await expect(card).toBeVisible();
    await expect(card.getByText('Running')).toBeVisible();
    await expect(card.getByText('Day 1 of 7')).toBeVisible();
    await expect(card.getByText('The brand is not set up yet.')).toBeVisible();
    await expect(page.getByText('1 of 3 active')).toBeVisible();

    // Set it up in the wizard, with the fact proposals from the site.
    await card.getByRole('button', { name: 'Set up in the wizard' }).click();
    await expect(page).toHaveURL(/\/onboarding\/wizard$/);
    await page.getByLabel('Name').fill('Browser Pitch');
    await page.getByLabel('Domain').fill('eksempel.no');
    await page.getByRole('button', { name: 'Analyse the site' }).click();
    const facts = page.getByTestId('wizard-facts');
    await expect(facts.getByRole('heading', { name: 'Facts about the brand' })).toBeVisible();
    const factFields = facts.getByRole('textbox', { name: 'Fact' });
    await expect(factFields).toHaveCount(2);
    await expect(factFields.nth(0)).toHaveValue('Eksempel AS ble grunnlagt i 1998.');
    await expect(facts.getByRole('checkbox', { name: 'Approve' }).nth(0)).not.toBeChecked();
    await facts.getByRole('checkbox', { name: 'Approve' }).nth(0).check();
    await factFields.nth(1).fill('Eksempel AS har butikk i Oslo, Bergen og Trondheim.');
    await facts.getByRole('button', { name: 'Add fact' }).click();
    await factFields.nth(2).fill('Eksempel AS har 40 ansatte.');
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(page.getByText(/USD/).first()).toBeVisible();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await page.getByRole('button', { name: 'Save and start the first run' }).click();
    await expect(page).toHaveURL(/\/brands\/\d+$/);
    const brandUrl = page.url();

    // The collection runs (faked) and the pitch is followed on its facts page and report.
    const collected = JSON.parse(
        execFileSync('php', ['tests/Browser/pitch-collect.php'], {
            env: { ...process.env, ...databaseEnv, EKKO_BROWSER_TEST: '1' },
            encoding: 'utf8',
        }),
    );
    expect(collected.runs).toBeGreaterThan(0);

    await page.goto(`${brandUrl}/facts`);
    await expect(page.getByRole('heading', { name: 'Facts and claims' })).toBeVisible();
    // Only the approved proposal and the added fact were saved; the unapproved proposal was not.
    await expect(page.getByRole('cell', { name: 'Eksempel AS ble grunnlagt i 1998.' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Eksempel AS har 40 ansatte.' })).toBeVisible();
    await expect(page.getByText('Eksempel AS har butikk i Oslo, Bergen og Trondheim.')).toHaveCount(0);
    const claims = page.getByTestId('claims');
    await expect(claims.getByText('Grunnlagt i 2005', { exact: true })).toBeVisible();
    await expect(claims.getByRole('link', { name: 'https://katalog.example.test/side' })).toBeVisible();

    await page.getByLabel('New fact').fill('Eksempel AS selger løpesko.');
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByRole('cell', { name: 'Eksempel AS selger løpesko.' })).toBeVisible();
    await page
        .getByRole('row', { name: /Eksempel AS har 40 ansatte\./ })
        .getByRole('button', { name: 'Switch off' })
        .click();
    const pending = page.getByTestId('pending-facts');
    await expect(pending.getByText('Eksempel AS har 40 ansatte.')).toBeVisible();
    await pending.getByRole('button', { name: 'Approve' }).click();
    await expect(pending).toHaveCount(0);
    await expect(page.getByText('3 of 50 facts')).toBeVisible();

    await page.goto(brandUrl);
    await page.getByRole('link', { name: 'Pitch report', exact: true }).click();
    await expect(page).toHaveURL(/\/pitch-report$/);
    const visibility = page.getByTestId('pitch-visibility');
    await expect(visibility.getByRole('heading', { name: 'Visibility versus competitors' })).toBeVisible();
    await expect(visibility.getByRole('heading', { name: 'Visibility for Browser Pitch' })).toBeVisible();
    await expect(visibility.getByTestId('kpi-card').first()).toContainText('%');
    await expect(page.getByTestId('pitch-sources').getByRole('cell', { name: 'katalog.example.test' })).toBeVisible();
    await expect(page.getByText('Pitch action 30')).toBeVisible();
    await expect(page.getByText('Pitch action 10')).toBeVisible();
    await expect(page.getByText('Pitch action 5')).toHaveCount(0);

    // Back on the partner, the pitch shows its progress and is converted to a customer.
    await page.getByRole('button', { name: 'Browser Pitch AS' }).first().click();
    await page.getByRole('button', { name: 'Browser partner' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/pitches');
    await expect(card.getByText('Browser Pitch', { exact: true })).toBeVisible();
    await expect(card.getByText(new RegExp(`${collected.runs} answers`))).toBeVisible();
    await card.getByRole('button', { name: 'Convert to customer' }).click();
    await expect(card.getByText('Converted', { exact: true })).toBeVisible();
    await expect(card.getByRole('button', { name: 'Convert to customer' })).toHaveCount(0);
    await card.getByRole('button', { name: 'Open the pitch report' }).click();
    await expect(page).toHaveURL(/\/pitch-report$/);
    await expect(page.getByText('Pitch action 30')).toBeVisible();
});
