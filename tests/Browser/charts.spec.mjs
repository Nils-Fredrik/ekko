import { readFileSync } from 'node:fs';
import { test, expect } from '@playwright/test';

// U02: the chart library loads only on pages with charts, and a theme switch repaints
// the charts without a reload (docs/ui-forbedring.md 5).

const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const chartChunk = manifest['resources/js/Components/Charts/support/echarts.js']?.file;

async function login(page) {
    await page.goto('/login');
    await page.locator('#email').fill(process.env.SMOKE_EMAIL);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

async function axisColors(page, testId) {
    return page
        .getByTestId(testId)
        .locator('svg text')
        .evaluateAll((texts) => [...new Set(texts.map((text) => text.getAttribute('fill')))].sort());
}

// One login for the whole file: the login is rate limited, and the other specs log in too.
test.describe.configure({ mode: 'serial' });
let page;
const requested = [];
const errors = [];

test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await page.addInitScript(() => {
        if (!sessionStorage.getItem('ekko.test.theme')) {
            localStorage.setItem('ekko.theme', 'dark');
            sessionStorage.setItem('ekko.test.theme', '1');
        }
    });
    page.on('request', (request) => requested.push(new URL(request.url()).pathname));
    page.on('pageerror', (error) => errors.push(error.message));
    await login(page);
});

test.afterAll(async () => {
    await page?.close();
});

test('the chart library is a separate chunk that pages without charts do not load', async () => {
    expect(chartChunk, 'ECharts chunk in the Vite manifest').toBeTruthy();
    for (const path of ['/dashboard', '/brands', '/inbox', '/reports']) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
    }
    expect(requested.filter((path) => path.endsWith(chartChunk))).toEqual([]);

    await page.goto('/dev/charts');
    await expect(
        page.getByTestId('example-sparkline').locator('figure[data-chart-ready="true"]').first(),
    ).toBeVisible();
    expect(requested.filter((path) => path.endsWith(chartChunk))).toHaveLength(1);
});

test('a theme switch repaints the charts without a reload', async () => {
    await page.goto('/dev/charts');
    const trend = page.getByTestId('example-trend');
    await expect(trend.locator('figure[data-chart-ready="true"]')).toBeVisible();
    await expect(trend.locator('svg text').first()).toBeVisible();

    const dark = await axisColors(page, 'example-trend');
    const muted = (theme) =>
        page.evaluate((value) => {
            document.documentElement.dataset.theme = value;
            const color = getComputedStyle(document.documentElement).getPropertyValue('--color-shell-muted').trim();
            document.documentElement.dataset.theme = 'dark';

            return color;
        }, theme);
    const [darkMuted, lightMuted] = [await muted('dark'), await muted('light')];
    expect(dark).toContain(darkMuted);
    await page.evaluate(() => {
        window.ekkoNoReload = true;
    });

    await page.getByTestId('account-menu').click();
    await page.getByRole('menuitem', { name: 'Light', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

    await expect.poll(() => axisColors(page, 'example-trend')).toContain(lightMuted);
    expect(await axisColors(page, 'example-trend')).not.toContain(darkMuted);
    expect(await page.evaluate(() => window.ekkoNoReload)).toBe(true);
    expect(errors).toEqual([]);
});

test('charts have a description, a table view and click events', async () => {
    await page.goto('/dev/charts');
    const trend = page.getByTestId('example-trend');
    const chart = trend.locator('[role="img"]').first();
    await expect(chart).toHaveAttribute('aria-label', 'Visibility against competitors');
    const descriptionId = await chart.getAttribute('aria-describedby');
    await expect(page.locator(`[id="${descriptionId}"]`)).toContainText('Own brand went from');

    await trend.getByTestId('chart-table-toggle').click();
    await expect(trend.locator('table tbody tr')).toHaveCount(28);
    await expect(chart).toBeHidden();
    await trend.getByTestId('chart-table-toggle').click();
    await expect(chart).toBeVisible();

    const heatmap = page.getByTestId('example-heatmap');
    await heatmap.scrollIntoViewIfNeeded();
    await expect(heatmap.locator('figure[data-chart-ready="true"]')).toBeVisible();
    const box = await heatmap.locator('[role="img"]').boundingBox();
    // The first cell: top-left of the plot, right of the row labels and below the engine names.
    const cell = await heatmap.locator('[role="img"] svg path').evaluateAll((paths) => {
        const candidates = paths
            .map((path) => path.getBoundingClientRect())
            .filter((rect) => rect.width > 20 && rect.height > 10 && rect.height < 40);
        candidates.sort((a, b) => a.top - b.top || a.left - b.left);

        return candidates[0]
            ? { x: candidates[0].left + candidates[0].width / 2, y: candidates[0].top + candidates[0].height / 2 }
            : null;
    });
    expect(cell, `heatmap cells inside ${JSON.stringify(box)}`).not.toBeNull();
    await page.mouse.click(cell.x, cell.y);
    await expect(page.getByTestId('chart-selected')).toContainText('{"key":"p1","column":"chatgpt_ui","row":"p1"}');
});

test('select gives { key } in every chart type', async () => {
    await page.goto('/dev/charts');
    const sources = page.getByTestId('example-sources');
    await sources.scrollIntoViewIfNeeded();
    await expect(sources.locator('figure[data-chart-ready="true"]')).toBeVisible();
    // The first bar: the highest of the bar-sized shapes, sorted from the top.
    const bar = await sources.locator('[role="img"] svg path').evaluateAll((paths) => {
        const bars = paths
            .map((path) => path.getBoundingClientRect())
            .filter((rect) => rect.width > 20 && rect.height > 6 && rect.height <= 20);
        bars.sort((a, b) => a.top - b.top);

        return bars[0] ? { x: bars[0].left + bars[0].width / 2, y: bars[0].top + bars[0].height / 2 } : null;
    });
    expect(bar).not.toBeNull();
    await page.mouse.click(bar.x, bar.y);
    await expect(page.getByTestId('chart-selected')).toContainText('{"key":"d1"}');
});

test('names with braces are drawn as written, not as label templates or rich text', async () => {
    const special = 'Brand {a} {b|c} }';
    await page.goto('/dev/charts');
    for (const testId of ['example-rank', 'example-heatmap']) {
        const example = page.getByTestId(testId);
        await example.scrollIntoViewIfNeeded();
        await expect(example.locator('figure[data-chart-ready="true"]')).toBeVisible();
        await expect
            .poll(() =>
                example.locator('[role="img"] svg text').evaluateAll((texts) => texts.map((t) => t.textContent)),
            )
            .toContain(special);
    }
});

test('the previous period says low n in the tooltip when only n is sent', async () => {
    await page.goto('/dev/charts');
    const trend = page.getByTestId('example-trend');
    await expect(trend.locator('figure[data-chart-ready="true"]')).toBeVisible();
    const box = await trend.locator('[role="img"]').boundingBox();
    // The previous period has n = 6 every 12th day and no low_sample flag; sweep the plot until
    // the tooltip shows one of those days.
    let previousRow = null;
    for (let x = box.x + 40; x < box.x + box.width - 10 && !previousRow?.includes('low n'); x += 6) {
        await page.mouse.move(x, box.y + box.height / 2);
        previousRow = await trend.evaluate((element) => {
            const rows = [...element.querySelectorAll('div[style*="justify-content"]')];
            const row = rows.find((candidate) => candidate.firstElementChild?.textContent === 'Previous period');

            return row?.textContent ?? null;
        });
    }
    expect(previousRow).toContain('n 6');
    expect(previousRow).toContain('low n');
});
