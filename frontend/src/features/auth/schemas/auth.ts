import { z } from 'zod';


export const loginSchema = z.object({
    email: z.string().min(1, 'Email is required').email('Enter a valid email'),
    password: z.string().min(1, 'Password is required'),
    remember: z.boolean().optional().default(false),
});

export type LoginInput = z.infer<typeof loginSchema>;


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
