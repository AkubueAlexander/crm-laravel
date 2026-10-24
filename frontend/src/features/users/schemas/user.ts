import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// Mirrors ManagedUserResource (8c.2a).
export const managedUserSchema = z.object({
    id: z.number(),
    name: z.string(),
    email: z.string(),
    role: z.string().nullable(),
    role_label: z.string().nullable(),
    active: z.boolean(),
    deactivated_at: z.string().nullable(),
    two_factor_enabled: z.boolean(),
    is_self: z.boolean(),
    created_at: z.string().nullable(),
});

export type ManagedUser = z.infer<typeof managedUserSchema>;

export const usersPageSchema = z.object({
    data: z.array(managedUserSchema),
    meta: paginationMetaSchema,
});

export type UsersPage = z.infer<typeof usersPageSchema>;

export const userRoleSchema = z.object({
    name: z.string(),
    label: z.string(),
    description: z.string(),
});

export type UserRole = z.infer<typeof userRoleSchema>;

export const userRolesResponseSchema = z.object({
    data: z.array(userRoleSchema),
});

export const USER_SORT_COLUMNS = ['name', 'email', 'created_at'] as const;
export type UserSortColumn = (typeof USER_SORT_COLUMNS)[number];

export const USER_STATUSES = ['active', 'deactivated'] as const;
export type UserStatus = (typeof USER_STATUSES)[number];

// URL-persisted filter/sort/page state for /users (8.7). Every field is optional; defaults are
// applied by the page so the URL stays clean for the default view.
export type UsersSearch = {
    q?: string;
    role?: string;
    status?: UserStatus;
    sort?: UserSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateUsersSearch(search: Record<string, unknown>): UsersSearch {
    const out: UsersSearch = {};

    // The router JSON-parses search values, so "123" can arrive as a number.
    const rawQ = typeof search.q === 'number' ? String(search.q) : search.q;
    if (typeof rawQ === 'string' && rawQ.trim() !== '') out.q = rawQ.trim();

    if (typeof search.role === 'string' && search.role.trim() !== '') out.role = search.role.trim();

    if (USER_STATUSES.includes(search.status as UserStatus)) out.status = search.status as UserStatus;
    if (USER_SORT_COLUMNS.includes(search.sort as UserSortColumn)) out.sort = search.sort as UserSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}
