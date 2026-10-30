import { expect, test } from '@playwright/test';
import { login } from './support/login';

// 15.6a: proves the whole stack boots (Vite -> proxy -> Laravel -> Postgres) and Sanctum cookie auth works end to end.

test('anonymous visitors are sent to the login page', async ({ page }) => {
    await page.goto('/deals');
    await expect(page).toHaveURL(/\/login/);
});

test('admin signs in and the deals board loads', async ({ page }) => {
    await login(page);
    await page.goto('/deals');
    await expect(page).toHaveURL(/\/deals/);
    await expect(page.getByText(/Loading deals/)).toHaveCount(0);
    await expect(page.getByText("Couldn't load deals.")).toHaveCount(0);
});
