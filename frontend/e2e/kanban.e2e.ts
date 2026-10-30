import { expect, test } from '@playwright/test';
import { cards, column, columns, dragCardToColumn, openBoard } from './support/board';

test.describe('Deals Kanban board (3.3)', () => {
    test('renders one column per pipeline stage with seeded deals', async ({ page }) => {
        await openBoard(page);
        await expect(columns(page)).toHaveCount(6);
        await expect(cards(page).filter({ hasText: 'E2E Move Deal' })).toHaveCount(1);
        await expect(cards(column(page, 'won')).filter({ hasText: 'E2E Won Deal' })).toHaveCount(1);
    });

    test('dragging a card to another column moves it (3.2 optimistic mutation)', async ({ page }) => {
        await openBoard(page);

        const source = column(page, 'lead');
        const target = column(page, 'qualified');
        const card = cards(source).filter({ hasText: 'E2E Move Deal' });
        await expect(card).toHaveCount(1);

        const moved = page.waitForResponse(
            (r) => /\/deals\/[^/]+\/transition/.test(r.url()) && r.request().method() === 'POST',
        );
        await dragCardToColumn(page, card, target);
        const res = await moved;
        expect(res.ok(), `${res.status()} ${await res.text()}`).toBeTruthy();

        await expect(cards(target).filter({ hasText: 'E2E Move Deal' })).toHaveCount(1);
        await expect(cards(source).filter({ hasText: 'E2E Move Deal' })).toHaveCount(0);
    });

    test('a stale lock_version shows the 409 conflict message and rolls the card back (3.2)', async ({ page }) => {
        await openBoard(page);

        const source = column(page, 'lead');
        const card = cards(source).filter({ hasText: 'E2E Stale Deal' });
        await expect(card).toHaveCount(1);
        const dealId = await card.getAttribute('data-deal-id');

        // Someone else moves the deal first (lock_version 0 -> 1) directly through the API.
        const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
        const other = await page.request.post(`/api/v1/deals/${dealId}/transition`, {
            data: { to_stage: 'qualified', lock_version: 0 },
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? ''),
                Accept: 'application/json',
                Origin: 'http://localhost:5183',
                Referer: 'http://localhost:5183/deals',
            },
        });
        expect(other.ok(), `${other.status()} ${await other.text()}`).toBeTruthy();

        // The board still holds lock_version 0, so this drag must be rejected.
        const rejected = page.waitForResponse(
            (r) => /\/deals\/[^/]+\/transition/.test(r.url()) && r.status() === 409,
        );
        await dragCardToColumn(page, card, column(page, 'qualified'));
        await rejected;

        await expect(page.getByRole('alert')).toContainText('updated by someone else');
        await expect(cards(source).filter({ hasText: 'E2E Stale Deal' })).toHaveCount(1);
    });
});