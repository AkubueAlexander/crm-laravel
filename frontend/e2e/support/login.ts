import { expect, type Page } from '@playwright/test';

// Seeded by DevTenantSeeder (tenant Acme).
export const ADMIN = { email: 'admin@acme.test', password: 'password' };

export async function login(page: Page, user: { email: string; password: string } = ADMIN): Promise<void> {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill(user.email);
    await page.getByLabel(/password/i).first().fill(user.password);
    await page.getByRole('button', { name: /sign in|log in|login/i }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await expect(page).not.toHaveURL(/\/login/);
}
