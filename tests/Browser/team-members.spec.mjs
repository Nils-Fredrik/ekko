import { expect, test } from '@playwright/test';

async function openTeam(page, email, teamId) {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(process.env.SMOKE_PASSWORD);
    await page.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto(`/teams/${teamId}`);
    return page.getByRole('list', { name: 'Team Members', exact: true });
}

test('a sole owner is visible alongside an empty membership message and a pending invitation role', async ({ page }) => {
    const members = await openTeam(page, process.env.TEAM_SOLO_EMAIL, process.env.TEAM_SOLO_ID);
    await expect(page.getByRole('heading', { name: 'Team Members', exact: true })).toBeVisible();
    const rows = members.getByRole('listitem');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('Morgan Owner');
    await expect(rows.first()).toContainText(process.env.TEAM_SOLO_EMAIL);
    await expect(rows.first().getByText('Owner', { exact: true })).toBeVisible();
    await expect(rows.first().getByRole('button')).toHaveCount(0);
    await expect(members.getByRole('img')).toHaveCount(0);
    await expect(page.getByText('No other team members yet.')).toBeVisible();
    const invite = page.getByRole('list', { name: 'Pending Team Invitations' }).getByRole('listitem');
    await expect(invite).toContainText('pending-editor@example.test');
    await expect(invite.getByText('Editor', { exact: true })).toBeVisible();
});

for (const actor of ['owner', 'reader']) {
    test(`the ${actor} sees the same roster with controls for their role`, async ({ page }) => {
        const email = actor === 'owner' ? process.env.SMOKE_WIZARD_EMAIL : process.env.TEAM_ROSTER_READER_EMAIL;
        const members = await openTeam(page, email, process.env.SMOKE_TENANT_ID);
        const owner = members.getByRole('listitem').first();
        await expect(owner).toContainText(process.env.SMOKE_WIZARD_EMAIL);
        await expect(owner.getByText('Owner', { exact: true })).toBeVisible();
        await expect(owner.getByRole('button')).toHaveCount(0);
        const reader = members.getByRole('listitem').filter({ hasText: process.env.TEAM_ROSTER_READER_EMAIL });
        await expect(reader).toHaveCount(1);
        await expect(reader.getByText('Casey Reader', { exact: true })).toBeVisible();
        await expect(reader.getByText('Reader', { exact: true })).toBeVisible();
        await expect(page.getByText('No other team members yet.')).toHaveCount(0);
        if (actor === 'owner') {
            await expect(reader.getByRole('button', { name: 'Reader', exact: true })).toBeVisible();
            await expect(reader.getByRole('button', { name: 'Remove', exact: true })).toBeVisible();
        } else {
            await expect(members.getByRole('button', { name: 'Remove', exact: true })).toHaveCount(0);
            await expect(members.getByRole('button', { name: 'Reader', exact: true })).toHaveCount(0);
            await expect(reader.getByRole('button', { name: 'Leave', exact: true })).toBeVisible();
        }
    });
}
