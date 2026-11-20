import { expect } from '@playwright/test';

export async function observeInertia(page) {
    const observe = () => {
        window.__ekkoUiResolvedProps = [];
        document.addEventListener('inertia:success', (event) => {
            window.__ekkoUiResolvedProps = [
                ...new Set([...window.__ekkoUiResolvedProps, ...Object.keys(event.detail.page.props)]),
            ];
        });
    };
    await page.addInitScript(observe);
    await page.evaluate(observe);
}

export async function settleCharts(page, { inertia = true, shared = false } = {}) {
    // networkidle can precede Vue mounting (especially with a cold JS bundle). Do not
    // enumerate figures until the application and its deferred-prop skeletons have settled.
    if (inertia) {
        await expect(page.locator('#app > *').first()).toBeVisible();
        const initial = JSON.parse((await page.locator('#app').getAttribute('data-page')) ?? '{}');
        const deferred = Object.values(initial.deferredProps ?? {}).flat();
        if (deferred.length) {
            // Public Inertia success events run after deferred responses have been applied.
            // Some pages have no aria-busy skeleton, so network/DOM appearance alone is insufficient.
            await expect
                .poll(
                    () =>
                        page.evaluate(
                            (keys) => keys.every((key) => window.__ekkoUiResolvedProps?.includes(key)),
                            deferred,
                        ),
                    { message: `Deferred Inertia props must finish: ${deferred.join(', ')}` },
                )
                .toBe(true);
        }
        await page.evaluate(
            () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
        );
    }
    if (shared) await expect(page.getByTestId('shared-report-trend').locator('figure')).toHaveCount(1);
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
    const figures = page.locator('figure[data-chart-ready]');
    for (let index = 0; index < (await figures.count()); index++) {
        const figure = figures.nth(index);
        await figure.scrollIntoViewIfNeeded();
        await expect(figure).toHaveAttribute('data-chart-ready', 'true');
        await expect(figure.locator('[role="img"] svg')).toBeVisible();
    }
    await expect(page.locator('figure[data-chart-ready="false"]')).toHaveCount(0);
    await page.evaluate(() => window.scrollTo(0, 0));
}
