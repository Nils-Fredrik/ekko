import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { criticalViolations } from './accessibility.mjs';

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
