import { test, expect } from '@playwright/test';

test('a shared report renders its saved trend with a gap and interactive values without sending the secret in a URL', async ({
    page,
}) => {
    const token = process.env.SMOKE_REPORT_TOKEN;
    const requests = [];
    page.on('request', (request) => requests.push(request));
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(`/shared-report/${process.env.SMOKE_TENANT_ID}/${process.env.SMOKE_REPORT_SELECTOR}#${token}`);
    await expect(page.getByText('Saved report insight')).toBeVisible();
    const trend = page.getByTestId('shared-report-trend');
    await expect(trend.locator('figure[data-chart-ready="true"]')).toBeVisible();
    // Both observations are isolated by the missing day, so ECharts draws a small symbol for each.
    const point = await trend.locator('[role="img"] svg path').evaluateAll((paths) => {
        const symbols = paths
            .map((path) => path.getBoundingClientRect())
            .filter((rect) => rect.width >= 4 && rect.width <= 10 && rect.height >= 4 && rect.height <= 10)
            .sort((a, b) => a.left - b.left);

        return symbols[0]
            ? { x: symbols[0].left + symbols[0].width / 2, y: symbols[0].top + symbols[0].height / 2 }
            : null;
    });
    expect(point).not.toBeNull();
    await page.mouse.click(point.x, point.y);
    await expect(trend.getByTestId('shared-trend-selection')).toContainText('40%');
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
