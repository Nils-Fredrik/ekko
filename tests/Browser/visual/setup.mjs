import { mkdirSync, rmSync } from 'node:fs';
import { chromium, expect } from '@playwright/test';
import seed from '../setup.mjs';
import { fixtureEnvironment } from './environment.mjs';

export default async function setup(config) {
    Object.assign(process.env, fixtureEnvironment);
    seed();
    rmSync('.ui-auth', { recursive: true, force: true });
    mkdirSync('.ui-auth', { mode: 0o700 });
    const browser = await chromium.connect(process.env.UI_BROWSER_WS, { exposeNetwork: '<loopback>' });
    try {
        for (const [role, key] of Object.entries({
            reader: 'SMOKE_EMAIL',
            owner: 'UI_OWNER_EMAIL',
            admin: 'UI_ADMIN_EMAIL',
            advisor: 'UI_ADVISOR_EMAIL',
            pitch: 'UI_PITCH_EMAIL',
            unverified: 'UI_UNVERIFIED_EMAIL',
            challenge: 'UI_CHALLENGE_EMAIL',
        })) {
            const context = await browser.newContext({ baseURL: config.projects[0].use.baseURL, locale: 'en-GB' });
            const page = await context.newPage();
            await page.goto('/login');
            await page.locator('#email').fill(process.env[key]);
            await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
            await page.locator('button[type="submit"]').click();
            const twoFactor = ['admin', 'advisor', 'challenge'].includes(role);
            await expect(page).toHaveURL(
                twoFactor ? /\/two-factor-challenge$/ : role === 'unverified' ? /\/email\/verify$/ : /\/dashboard$/,
            );
            if (twoFactor && role !== 'challenge') {
                await page.locator('form button[type="button"]').click();
                await page.locator('#recovery_code').fill('recovery-code-1');
                await page.locator('button[type="submit"]').click();
                await expect(page).toHaveURL(/\/dashboard$/);
            }
            await context.storageState({ path: `.ui-auth/${role}.json` });
            await context.close();
        }
    } finally {
        await browser.close();
    }
}
