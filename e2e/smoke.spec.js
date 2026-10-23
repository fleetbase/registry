// Smoke test against a seeded local Registry Service (scripts/e2e-server.sh): browse the catalog,
// sign in as a publisher, create a token, then review, approve and publish as staff.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const seed = () => JSON.parse(readFileSync(new URL('../storage/e2e/seed.json', import.meta.url), 'utf8'));

async function signIn(page, { email, password }) {
    await page.goto('/sign-in');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.locator('[data-sign-in-submit]').click();
    await expect(page.locator('[data-account-menu]')).toBeVisible();
}

async function signOut(page) {
    await page.locator('[data-account-menu]').click();
    await page.locator('[data-sign-out]').click();
    await expect(page.locator('[data-sign-in]')).toBeVisible();
}

test('public catalog: search, detail and install instructions', async ({ page }) => {
    const { extensions } = seed();
    await page.goto('/');
    await expect(page.locator('[data-extension]').first()).toBeVisible();

    const slug = extensions[0];
    await page.locator(`[data-extension="${slug}"]`).click();
    await expect(page).toHaveURL(new RegExp(`/extensions/${slug}$`));
    await expect(page.locator('[data-extension-name]')).toBeVisible();
    await expect(page.locator('[data-versions]')).toBeVisible();

    await page.getByRole('tab', { name: 'CLI' }).click();
    await expect(page.locator('[data-install="cli"]')).toContainText(`flb install ${slug}`);

    await page.goto('/no/such/page');
    await expect(page.locator('[data-error-status="404"]')).toBeVisible();
});

test('publisher: Studio, a listing and a token shown once', async ({ page }) => {
    const { publisher } = seed();
    await signIn(page, publisher);
    await expect(page).toHaveURL(/\/studio$/);

    await page.goto('/account/tokens');
    await expect(page.getByRole('heading', { name: 'Tokens', level: 1 })).toBeVisible();
    // With no tokens yet the form is already open.
    if (!(await page.locator('[data-new-token-form]').isVisible())) {
        await page.locator('[data-new-token]').click();
    }
    await page.locator('[data-new-token-form]').getByLabel('Name').fill('Playwright smoke');
    await page.locator('[data-create-token]').click();
    const value = page.locator('[data-token-value]');
    await expect(value).toHaveText(/^flbp_[A-Za-z0-9]{40}$/);
    const plaintext = await value.textContent();
    await page.locator('[data-token-stored]').check();
    await page.locator('[data-token-done]').click();
    await expect(page.locator('[data-token]').filter({ hasText: 'Playwright smoke' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText(plaintext);

    // The session cookie is HttpOnly: scripts on the page never see it.
    const cookies = await page.context().cookies();
    expect(cookies.find((cookie) => /[-_]session$/.test(cookie.name))?.httpOnly).toBe(true);
    await signOut(page);
});

test('staff: review queue, approve and publish, then it is in the catalog', async ({ page }) => {
    const { staff, queued_release: releaseId } = seed();
    await signIn(page, staff);

    await page.goto('/review');
    await page.locator('[data-queue-row]').first().click();
    await expect(page).toHaveURL(new RegExp(`/review/releases/${releaseId}$`));
    await expect(page.locator('[data-check]')).toHaveCount(8);
    const name = (await page.locator('h1.h2').textContent()).replace(/\s*v?\d+\.\d+\.\d+.*$/, '').trim();

    if (await page.locator('[data-claim]').isVisible()) {
        await page.locator('[data-claim]').click();
        await expect(page.locator('[data-claim]')).toHaveCount(0);
    }
    for (const code of ['R1', 'R2', 'R3', 'R4', 'R5', 'R6']) {
        // R1 and R4 may already be passed from the automated checks; clicking again would clear them.
        const item = page.locator(`[data-decision] [data-item="${code}"]`);
        if ((await item.getAttribute('data-mark')) !== 'pass') {
            await item.getByRole('button', { name: 'Pass' }).click();
        }
    }
    await page.locator('[data-decision] [data-approve]').click();
    await page.locator('[data-confirm-approve]').click();
    // Approving with "publish as soon as it's approved" publishes in the same request.
    await expect(page.locator('[data-decided]')).toContainText('Live', { timeout: 20_000 });

    await page.goto(`/?q=${encodeURIComponent(name)}`);
    await expect(page.locator('[data-extension]').filter({ hasText: name })).toBeVisible();
});

test('theme toggle switches between light and dark and is remembered', async ({ page }) => {
    await page.goto('/');
    const theme = () => page.evaluate(() => document.documentElement.dataset.theme);
    const before = await theme();
    await page.locator('[data-theme-toggle]').click();
    const after = await theme();
    expect(after).not.toBe(before);
    expect(['light', 'dark']).toContain(after);
    await page.reload();
    expect(await theme()).toBe(after);
});

test('Discover works on a phone @phone', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('[data-extension]').first()).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
});
