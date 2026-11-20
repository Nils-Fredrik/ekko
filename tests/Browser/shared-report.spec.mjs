import { test, expect } from '@playwright/test';

for (const { locale, tokenKey, selectorKey, started, completed } of [
    { locale: 'en', tokenKey: 'SMOKE_REPORT_TOKEN', selectorKey: 'SMOKE_REPORT_SELECTOR', started: 'Action started', completed: 'Action completed' },
    { locale: 'nb', tokenKey: 'SMOKE_REPORT_NB_TOKEN', selectorKey: 'SMOKE_REPORT_NB_SELECTOR', started: 'Tiltak startet', completed: 'Tiltak fullført' },
]) {
    test(`a ${locale} shared report keeps its language and shows the same trend as the PDF without leaking its token`, async ({
        page,
    }) => {
        const token = process.env[tokenKey];
        const requests = [];
        page.on('request', (request) => requests.push(request));
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto(`/shared-report/${process.env.SMOKE_TENANT_ID}/${process.env[selectorKey]}#${token}`);
        await expect(page.getByText('Saved report insight')).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        await expect(page.getByTestId('shared-report-trend')).toHaveCount(0);

        const trend = page.getByTestId('pdf-report-trend');
        await expect(trend).toBeVisible();
        const values = trend.getByTestId('pdf-trend-values');
        await expect(values.locator('tbody tr')).toHaveCount(2);
        await expect(values).toContainText('2026-10-01');
        await expect(values).toContainText('40%');
        await expect(values).toContainText('2026-10-03');
        await expect(values).toContainText('60%');
        await expect(values).not.toContainText('2026-10-02');
        await expect(trend.locator('circle title').nth(0)).toHaveText('2026-10-01 · 40%');
        await expect(trend.locator('circle title').nth(1)).toHaveText('2026-10-03 · 60%');

        const markers = trend.getByTestId('pdf-trend-markers');
        await expect(markers.locator('li')).toHaveCount(2);
        await expect(markers).toContainText('2026-10-02');
        await expect(markers).toContainText('2026-10-03');
        await expect(markers).toContainText(started);
        await expect(markers).toContainText(completed);
        await expect(markers).toContainText('Published action marker');
        await expect(markers.locator('a')).toHaveCount(0);
        await expect(trend.locator('[data-marker-date="2026-10-02"] title')).toContainText('Published action marker');
        await expect(trend.locator('[data-marker-date="2026-10-02"] title')).toContainText(started);

        expect(requests.some((request) => request.headers().authorization === `Bearer ${token}`)).toBe(true);
        expect(requests.every((request) => !request.url().includes(token))).toBe(true);
    });
}
