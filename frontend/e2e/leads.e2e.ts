import { expect, test } from '@playwright/test';
import { cards, column, openBoard } from './support/board';
import { signIn } from './support/session';

test.describe('Lead -> Deal conversion (8a.2)', () => {
    test('converting a lead marks it converted and puts a new deal on the board', async ({ page }) => {
        await signIn(page);
        await page.goto('/leads');

        const row = page.getByRole('row').filter({ hasText: 'Lovelace' });
        await expect(row).toHaveCount(1);
        await row.getByRole('button', { name: 'Convert' }).click();

        const dialog = page.getByRole('dialog', { name: 'Convert lead' });
        await expect(dialog).toBeVisible();
        await dialog.getByLabel('Deal name').fill('E2E Converted Deal');
        await dialog.getByLabel('Amount').fill('2500');

        const converted = page.waitForResponse(
            (r) => /\/leads\/[^/]+\/convert/.test(r.url()) && r.request().method() === 'POST',
        );
        await dialog.getByRole('button', { name: 'Convert lead' }).click();
        const res = await converted;
        expect(res.ok(), `${res.status()} ${await res.text()}`).toBeTruthy();

        await expect(dialog).toContainText('was converted');
        await dialog.getByRole('button', { name: 'Done' }).click();
        await expect(dialog).toBeHidden();

        // The leads list was invalidated: the row is now converted and no longer offers Convert.
        await expect(row).toContainText('converted');
        await expect(row.getByRole('button', { name: 'Convert' })).toHaveCount(0);

        // The new deal exists on the board, in the starting stage, with the amount we entered.
        await openBoard(page);
        const deal = cards(column(page, 'lead')).filter({ hasText: 'E2E Converted Deal' });
        await expect(deal).toHaveCount(1);
        await expect(deal).toContainText('2,500');
    });
});