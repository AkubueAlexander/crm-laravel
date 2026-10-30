import type { Page } from '@playwright/test';
import { login } from './login';

/** Signs in via the 15.6a helper (Sanctum cookie flow, 2.1). Single source of truth for E2E auth. */
export async function signIn(page: Page): Promise<void> {
  await login(page);
}