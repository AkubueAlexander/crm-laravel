import { expect, type Locator, type Page } from '@playwright/test';
import { signIn } from './session';

export const columns = (page: Page): Locator => page.getByTestId('stage-column');

export const column = (page: Page, stage: string): Locator =>
    page.locator(`[data-testid="stage-column"][data-stage="${stage}"]`);

export const cards = (scope: Page | Locator): Locator => scope.getByTestId('deal-card');

/** Signs in (Sanctum cookie flow, 2.1) then opens the Deals board. */
export async function openBoard(page: Page): Promise<void> {
    await signIn(page);
    await page.goto('/deals');
    await expect(page.getByTestId('deals-board')).toBeVisible();
    await expect(columns(page).first()).toBeVisible();
}

/**
 * dnd-kit's PointerSensor needs real pointer events and a >4px move before it
 * activates, so Locator.dragTo() is unreliable. Drive the mouse manually.
 */
export async function dragCardToColumn(page: Page, card: Locator, target: Locator): Promise<void> {
    await card.scrollIntoViewIfNeeded();
    const from = await card.boundingBox();
    const to = await target.boundingBox();
    if (!from || !to) throw new Error('drag source/target not visible');

    const sx = from.x + from.width / 2;
    const sy = from.y + from.height / 2;

    await page.mouse.move(sx, sy);
    await page.mouse.down();
    await page.mouse.move(sx + 10, sy + 10, { steps: 5 });
    await page.mouse.move(to.x + to.width / 2, to.y + 60, { steps: 15 });
    await page.mouse.up();
}