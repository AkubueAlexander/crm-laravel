// Single source of truth is 15.6a's e2e/support/login.ts (matches DevTenantSeeder).
// Override with E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD if needed.
import { ADMIN } from './login';

export const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? ADMIN.email;
export const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? ADMIN.password;