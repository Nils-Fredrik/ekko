import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { criticalViolations } from './accessibility.mjs';
import { observeInertia, settleCharts } from './settle.mjs';

test('axe gate detects an intentionally unnamed image input', async ({ page }) => {
    await page.setContent(
        '<!doctype html><html lang="en"><title>Gate fixture</title><main><form><input type="image" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"></form></main></html>',
    );
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(criticalViolations(scan).map((violation) => violation.id)).toContain('input-image-alt');
    await page.locator('input').evaluate((input) => input.setAttribute('alt', 'Submit example'));
    expect(
        criticalViolations(
            await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze(),
        ),
    ).toEqual([]);
});

test('capture preparation waits for mounted deferred charts below the viewport', async ({ page }) => {
    await page.setContent(
        '<!doctype html><html lang="en"><title>Lazy chart fixture</title><div id="app"></div></html>',
    );
    await observeInertia(page);
    await page
        .locator('#app')
        .evaluate((root) =>
            root.setAttribute('data-page', JSON.stringify({ deferredProps: { charts: ['chartData'] } })),
        );
    await page.evaluate(() => {
        setTimeout(() => {
            document.querySelector('#app').innerHTML = '<main>Initial page without a loading skeleton</main>';
            setTimeout(() => {
                document.querySelector('main').innerHTML =
                    '<div style="height:2000px">Above chart</div><figure data-chart-ready="false"><div role="img" aria-label="Fixture chart" style="height:200px">Loading chart</div></figure>';
                document.dispatchEvent(
                    new CustomEvent('inertia:success', { detail: { page: { props: { chartData: {} } } } }),
                );
                const figure = document.querySelector('figure');
                const observer = new IntersectionObserver((entries) => {
                    if (!entries.some((entry) => entry.isIntersecting)) return;
                    figure.querySelector('[role="img"]').innerHTML =
                        '<svg width="200" height="100"><rect width="100" height="50" fill="blue" /></svg>';
                    figure.dataset.chartReady = 'true';
                    observer.disconnect();
                });
                observer.observe(figure);
            }, 250);
        }, 250);
    });
    await settleCharts(page);
    await expect(page.locator('figure')).toHaveAttribute('data-chart-ready', 'true');
    await expect(page.locator('figure svg rect')).toHaveCount(1);
    expect(await page.evaluate(() => window.scrollY)).toBe(0);
});
