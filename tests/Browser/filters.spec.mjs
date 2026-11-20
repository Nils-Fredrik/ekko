import { test, expect } from '@playwright/test';

test('analysis filters use partial visits, survive shared URLs, saved views and mobile editing', async ({ page, context }) => {
    await page.goto('/login');
    await page.locator('#email').fill(process.env.FILTER_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    const path = `/brands/${process.env.FILTER_BRAND_ID}`;
    await page.goto(`${path}?from=2026-10-01&to=2026-10-03`);
    const bar = page.getByTestId('analysis-filters');
    await expect(bar).toContainText('2026-10-01');
    await page.evaluate(() => { window.filterPageIdentity = 'unchanged'; });
    const visits = [];
    page.on('request', (request) => { if (new URL(request.url()).pathname === path) visits.push(request); });
    await bar.getByRole('button', { name: 'Add or change filters', exact: true }).click();
    await bar.getByRole('combobox', { name: 'Comparison period', exact: true }).selectOption('year');
    await bar.getByRole('combobox', { name: 'Market', exact: true }).selectOption({ label: 'NO' });
    await bar.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(bar).toContainText('2025-10-01');
    await expect(bar.getByRole('button', { name: 'Remove NO', exact: true })).toBeVisible();
    expect(await page.evaluate(() => window.filterPageIdentity)).toBe('unchanged');
    expect(visits.some((request) => request.headers()['x-inertia-partial-data']?.includes('chartData'))).toBe(true);
    expect(visits.every((request) => !request.isNavigationRequest())).toBe(true);
    const shared = await context.newPage();
    await shared.goto(page.url());
    await expect(shared.getByTestId('analysis-filters').getByRole('button', { name: 'Remove NO', exact: true })).toBeVisible();
    await expect(shared.getByTestId('analysis-filters')).toContainText('2025-10-01');
    await shared.close();

    await bar.locator('summary').click();
    await bar.getByLabel('View name', { exact: true }).fill('Saved test view');
    await bar.getByRole('button', { name: 'Save current view', exact: true }).click();
    await expect(bar.getByRole('combobox', { name: 'Saved views', exact: true })).toBeVisible();
    await bar.getByRole('button', { name: 'Remove NO', exact: true }).click();
    await expect(bar.getByRole('button', { name: 'Remove NO', exact: true })).toHaveCount(0);
    await page.goBack();
    await expect(bar.getByRole('button', { name: 'Remove NO', exact: true })).toBeVisible();
    await page.goForward();
    await expect(bar.getByRole('button', { name: 'Remove NO', exact: true })).toHaveCount(0);
    if (!(await bar.getByRole('combobox', { name: 'Saved views', exact: true }).isVisible())) await bar.locator('summary').click();
    await bar.getByRole('combobox', { name: 'Saved views', exact: true }).selectOption({ label: 'Saved test view' });
    await expect(bar.getByRole('button', { name: 'Remove NO', exact: true })).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await bar.getByRole('button', { name: 'Add or change filters', exact: true }).click();
    const sheet = page.getByRole('dialog', { name: 'Filters', exact: true });
    await expect(sheet).toBeVisible();
    await expect.poll(async () => { const box = await sheet.boundingBox(); return box.y + box.height; }).toBeLessThanOrEqual(845);
    await sheet.getByRole('combobox', { name: 'Market', exact: true }).selectOption('');
    await sheet.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(sheet).toHaveCount(0);
    await expect(bar.getByRole('button', { name: 'Remove NO', exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => window.filterPageIdentity)).toBe('unchanged');
    await page.setViewportSize({ width: 1440, height: 1000 });

    // Ordinary line clicks (without a point dataIndex) also cross-filter.
    const engine = page.getByRole('img', { name: 'ChatGPT', exact: true });
    await expect(engine.locator('svg')).toBeVisible();
    async function linePoint(chart, seriesWidth) {
        return chart.locator('svg path').evaluateAll((paths, width) => {
            const path = paths.find((p) => p.getAttribute('stroke-width') === String(width) && p.getTotalLength() > 50);
            if (!path) return null;
            const point = path.getPointAtLength(path.getTotalLength() / 2);
            const screen = new DOMPoint(point.x, point.y).matrixTransform(path.getScreenCTM());
            return { x: screen.x, y: screen.y };
        }, seriesWidth);
    }
    const enginePoint = await linePoint(engine, 1.5);
    expect(enginePoint).not.toBeNull();
    await page.mouse.click(enginePoint.x, enginePoint.y);
    await expect(page).toHaveURL(/engine=filter_engine/);
    await expect(bar.getByRole('button', { name: 'Remove ChatGPT', exact: true })).toBeVisible();
    const trend = page.getByRole('img', { name: 'Visibility over time', exact: true });
    await trend.scrollIntoViewIfNeeded();
    await expect(trend.locator('svg')).toBeVisible();
    const competitorPoint = await linePoint(trend, 1.5);
    expect(competitorPoint).not.toBeNull();
    await page.mouse.click(competitorPoint.x, competitorPoint.y);
    await expect(page).toHaveURL(/competitor=\d+/);
    await expect(bar.getByRole('button', { name: 'Remove Comparison fixture', exact: true })).toBeVisible();

    // The topic graph and the engine panels use the same URL filter mechanism.
    await page.goto(`${path}/prompts?from=2026-10-01&to=2026-10-03`);
    const topics = page.getByRole('img', { name: 'Visibility by topic', exact: true });
    await expect(topics.locator('svg')).toBeVisible();
    const topicBar = await topics.locator('svg path').evaluateAll((paths) => {
        const bars = paths.map((p) => p.getBoundingClientRect()).filter((r) => r.width > 100 && r.height > 5 && r.height < 35);
        const first = bars.sort((a, b) => a.top - b.top)[0];
        return first ? { x: first.left + first.width / 2, y: first.top + first.height / 2 } : null;
    });
    expect(topicBar).not.toBeNull();
    await page.mouse.click(topicBar.x, topicBar.y);
    await expect(page).toHaveURL(/topic=\d+/);
    await expect(bar.getByRole('button', { name: /Remove Topic/ })).toBeVisible();
});
