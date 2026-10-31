import { expect, test, type Page } from '@playwright/test';
import { signIn } from './support/session';

const dd = (page: Page, label: string) =>
    page.getByText(label, { exact: true }).locator('xpath=following-sibling::dd[1]');

test.describe('Quote builder (10.1)', () => {
    test('a 3-line quote shows live totals, saves, and the server total matches', async ({ page }) => {
        await signIn(page);
        await page.goto('/quotes/new');

        await page.getByLabel('Title', { exact: true }).fill('E2E Multi-line Quote');

        // Line 1: catalog product (E2E Widget, 100.00, standard 7.5%), quantity 3.
        await page.getByLabel('Search the catalog').fill('E2E-WIDGET');
        await page.getByRole('button', { name: /E2E-WIDGET/ }).click();
        await page.getByLabel('Line 1 quantity', { exact: true }).fill('3');

        // Line 2: custom, reduced 5%, 10% discount: 2 x 50.00 = 90.00 net.
        await page.getByRole('button', { name: 'Add custom line' }).click();
        await page.getByLabel('Line 2 name', { exact: true }).fill('E2E Consulting');
        await page.getByLabel('Line 2 quantity', { exact: true }).fill('2');
        await page.getByLabel('Line 2 unit price', { exact: true }).fill('50.00');
        await page.getByLabel('Line 2 discount percent', { exact: true }).fill('10');
        await page.getByLabel('Line 2 tax category', { exact: true }).selectOption('reduced');

        // Line 3: custom, exempt: 1 x 20.00.
        await page.getByRole('button', { name: 'Add custom line' }).click();
        await page.getByLabel('Line 3 name', { exact: true }).fill('E2E Exempt Fee');
        await page.getByLabel('Line 3 quantity', { exact: true }).fill('1');
        await page.getByLabel('Line 3 unit price', { exact: true }).fill('20.00');
        await page.getByLabel('Line 3 tax category', { exact: true }).selectOption('exempt');

        // Live preview (useWatch): subtotal 300 + 90 + 20, tax 22.50 + 4.50 + 0.
        await expect(dd(page, 'Subtotal')).toContainText('410.00');
        await expect(dd(page, 'Tax (estimate)')).toContainText('27.00');
        await expect(dd(page, 'Total (estimate)')).toContainText('437.00');

        const created = page.waitForResponse(
            (r) => r.request().method() === 'POST' && /\/quotes$/.test(new URL(r.url()).pathname),
        );
        await page.getByRole('button', { name: 'Create quote' }).click();
        const res = await created;
        expect(res.ok(), `${res.status()} ${await res.text()}`).toBeTruthy();

        // Redirected to the saved quote, which shows the SERVER-computed totals (QuoteTotals, not the preview).
        await expect(page).toHaveURL(/\/quotes\/\d+$/);
        await expect(page.getByText(/Saved total \(from the server\)/)).toContainText('437.00');
        await expect(page.getByText(/Saved total \(from the server\)/)).toContainText('27.00');
        await expect(page.getByLabel('Line 3 name', { exact: true })).toHaveValue('E2E Exempt Fee');
    });

    test('an empty title is rejected client-side without calling the API', async ({ page }) => {
        await signIn(page);
        await page.goto('/quotes/new');

        let posted = false;
        page.on('request', (r) => {
            if (r.method() === 'POST' && /\/quotes$/.test(new URL(r.url()).pathname)) posted = true;
        });

        await page.getByRole('button', { name: 'Create quote' }).click();
        await expect(page.getByText('Title is required.')).toBeVisible();
        expect(posted).toBe(false);
    });
});