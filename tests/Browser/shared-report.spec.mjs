import { test, expect } from '@playwright/test';

test('a shared report renders its saved trend with a gap and interactive values without sending the secret in a URL', async ({
    page,
}) => {
    const token = process.env.SMOKE_REPORT_TOKEN;
    const requests = [];
    page.on('request', (request) => requests.push(request));
    await page.goto(`/shared-report/${process.env.SMOKE_TENANT_ID}/${process.env.SMOKE_REPORT_SELECTOR}#${token}`);
    await expect(page.getByText('Saved report insight')).toBeVisible();
    const trend = page.getByTestId('shared-report-trend');
    await expect(trend.locator('figure[data-chart-ready="true"]')).toBeVisible();
    await trend.getByTestId('chart-table-toggle').click();
    await expect(trend.locator('table')).toContainText('40%');
    await expect(trend.locator('table tbody tr')).toHaveCount(3);
    await expect(trend.locator('table tbody tr').nth(1)).toContainText('Oct 2');
    await trend.locator('summary').click();
    await trend.getByRole('button', { name: /2026-10-03.*60%/ }).click();
    await expect(trend.getByTestId('shared-trend-selection')).toContainText('60%');
    expect(requests.some((request) => request.headers().authorization === `Bearer ${token}`)).toBe(true);
    expect(requests.every((request) => !request.url().includes(token))).toBe(true);
});
