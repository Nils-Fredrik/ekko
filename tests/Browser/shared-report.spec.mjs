import { test, expect } from '@playwright/test';

for (const { locale, tokenKey, selectorKey } of [
    { locale: 'en', tokenKey: 'SMOKE_REPORT_TOKEN', selectorKey: 'SMOKE_REPORT_SELECTOR' },
    { locale: 'nb', tokenKey: 'SMOKE_REPORT_NB_TOKEN', selectorKey: 'SMOKE_REPORT_NB_SELECTOR' },
]) {
    test(`a ${locale} shared report keeps its language and supports interactive values without leaking its token`, async ({
        page,
    }) => {
        const token = process.env[tokenKey];
        const requests = [];
        page.on('request', (request) => requests.push(request));
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto(`/shared-report/${process.env.SMOKE_TENANT_ID}/${process.env[selectorKey]}#${token}`);
        await expect(page.getByText('Saved report insight')).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        const [forty, sixty, gapDate] = await page.evaluate(
            (language) => [
                new Intl.NumberFormat(language, { style: 'percent', maximumFractionDigits: 1 }).format(0.4),
                new Intl.NumberFormat(language, { style: 'percent', maximumFractionDigits: 1 }).format(0.6),
                new Intl.DateTimeFormat(language, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(
                    new Date(Date.UTC(2026, 9, 2)),
                ),
            ],
            locale,
        );
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
        await expect.poll(() => trend.getByTestId('shared-trend-selection').textContent()).toContain(forty);
        await trend.getByTestId('chart-table-toggle').click();
        expect(await trend.locator('table').textContent()).toContain(forty);
        await expect(trend.locator('table tbody tr')).toHaveCount(3);
        await expect(trend.locator('table tbody tr').nth(1)).toContainText(gapDate);
        await trend.locator(':scope > details > summary').click();
        await trend.getByRole('button', { name: /2026-10-03.*60\s*%/ }).click();
        await expect.poll(() => trend.getByTestId('shared-trend-selection').textContent()).toContain(sixty);
        const markers = trend.getByTestId('chart-markers');
        await markers.locator('summary').click();
        await expect(markers.locator('li')).toHaveCount(2);
        await expect(markers).toContainText('2026-10-02');
        await expect(markers).toContainText(locale === 'en' ? 'Action started' : 'Tiltak startet');
        await expect(markers).toContainText(locale === 'en' ? 'Action completed' : 'Tiltak fullført');
        await expect(markers.locator('a')).toHaveCount(0);
        await markers.locator('summary').click();
        // A marker on the missing observation day still receives pointer events.
        await trend.getByTestId('chart-table-toggle').click();
        const marker = await trend.locator('[role="img"] svg path').evaluateAll((paths) => {
            const lines = paths.map((path) => path.getBoundingClientRect())
                .filter((rect) => rect.width < 2 && rect.height > 100).sort((a, b) => a.left - b.left);
            return lines[0] ? { x: lines[0].left, y: lines[0].top + lines[0].height / 2 } : null;
        });
        expect(marker).not.toBeNull();
        await page.mouse.move(marker.x, marker.y);
        await expect(trend.locator('figure').getByText(locale === 'en'
            ? '2026-10-02 · Action: Published action marker – Action started'
            : '2026-10-02 · Tiltak: Published action marker – Tiltak startet', { exact: true })).toBeVisible();
        await page.mouse.click(marker.x, marker.y);
        await expect(trend.getByTestId('shared-marker-selection')).toContainText('Published action marker');
        expect(requests.some((request) => request.headers().authorization === `Bearer ${token}`)).toBe(true);
        expect(requests.every((request) => !request.url().includes(token))).toBe(true);
    });
}
