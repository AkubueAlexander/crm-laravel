import { z } from 'zod';

// UX layer only — the Laravel Form Request on AuthenticatedSessionController
// is the real security boundary (0.0/14.2). Kept in lockstep by convention,
// not by code sharing, since the two run in different languages.
export const loginSchema = z.object({
    email: z.string().min(1, 'Email is required').email('Enter a valid email'),
    password: z.string().min(1, 'Password is required'),
    remember: z.boolean().optional().default(false),
});

export type LoginInput = z.infer<typeof loginSchema>;

// Shape returned by GET /api/v1/me (UserResource) — the frontend's own
// runtime check that the backend Resource hasn't silently drifted (7.0/15.4).
export const tenantSchema = z.object({
    id: z.union([z.string(), z.number()]),
    name: z.string(),
    slug: z.string(),
    branding: z.record(z.unknown()).nullable(),
});

export const currentUserSchema = z.object({
    id: z.union([z.string(), z.number()]),
    name: z.string(),
    email: z.string(),
    two_factor_enabled: z.boolean(),
    tenant: tenantSchema,
    roles: z.array(z.string()),
    permissions: z.array(z.string()),
});

export type CurrentUser = z.infer<typeof currentUserSchema>;
export type Tenant = z.infer<typeof tenantSchema>;
