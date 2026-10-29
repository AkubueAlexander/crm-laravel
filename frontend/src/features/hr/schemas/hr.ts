import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// 13.1c: HR (employees, teams, territories). Mirrors EmployeeResource / HrUnitResource.
export const HR_UNIT_KINDS = ['team', 'territory'] as const;
export type HrUnitKind = (typeof HR_UNIT_KINDS)[number];

export const HR_UNIT_LABELS: Record<HrUnitKind, { singular: string; plural: string; path: string }> = {
    team: { singular: 'team', plural: 'Teams', path: 'teams' },
    territory: { singular: 'territory', plural: 'Territories', path: 'territories' },
};

const dateTime = z.string().nullable().optional();

export const hrUnitSchema = z.object({
    id: z.number(),
    kind: z.enum(HR_UNIT_KINDS),
    name: z.string(),
    description: z.string().nullable(),
    is_active: z.boolean(),
    created_at: dateTime,
    updated_at: dateTime,
});

export const hrUnitsPageSchema = z.object({
    data: z.array(hrUnitSchema),
    meta: paginationMetaSchema,
});

const unitRefSchema = z.object({ id: z.number(), name: z.string() }).nullable();

export const employeeSchema = z.object({
    id: z.number(),
    name: z.string(),
    email: z.string().nullable(),
    phone: z.string().nullable(),
    job_title: z.string().nullable(),
    hire_date: z.string().nullable(),
    is_active: z.boolean(),
    user_id: z.number().nullable(),
    user_name: z.string().nullable(),
    team: unitRefSchema,
    territory: unitRefSchema,
    created_at: dateTime,
    updated_at: dateTime,
});

export const employeesPageSchema = z.object({
    data: z.array(employeeSchema),
    meta: paginationMetaSchema,
});

export const userOptionSchema = z.object({ id: z.number(), name: z.string() });
// GET users/options: accept a bare array or { data }.
export const userOptionsSchema = z.union([z.array(userOptionSchema), z.object({ data: z.array(userOptionSchema) }).transform((v) => v.data)]);

export type HrUnit = z.infer<typeof hrUnitSchema>;
export type HrUnitsPage = z.infer<typeof hrUnitsPageSchema>;
export type Employee = z.infer<typeof employeeSchema>;
export type EmployeesPage = z.infer<typeof employeesPageSchema>;
export type UserOption = z.infer<typeof userOptionSchema>;

// One Zod schema per form (8.3). The Laravel Actions stay the real boundary.
export const unitFormSchema = z.object({
    name: z.string().trim().min(1, 'Enter a name').max(120, 'Max 120 characters'),
    description: z.string().trim().max(500, 'Max 500 characters'),
    is_active: z.boolean(),
});
export type UnitFormValues = z.infer<typeof unitFormSchema>;

export const employeeFormSchema = z.object({
    name: z.string().trim().min(1, 'Enter a name').max(160, 'Max 160 characters'),
    email: z
        .string()
        .trim()
        .max(190, 'Max 190 characters')
        .refine((v) => v === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v), 'Enter a valid email address'),
    phone: z.string().trim().max(40, 'Max 40 characters'),
    job_title: z.string().trim().max(120, 'Max 120 characters'),
    hire_date: z.string().refine((v) => v === '' || /^\d{4}-\d{2}-\d{2}$/.test(v), 'Use a valid date'),
    user_id: z.string(),
    team_id: z.string(),
    territory_id: z.string(),
    is_active: z.boolean(),
});
export type EmployeeFormValues = z.infer<typeof employeeFormSchema>;

export function employeeToForm(e: Employee | null): EmployeeFormValues {
    return {
        name: e?.name ?? '',
        email: e?.email ?? '',
        phone: e?.phone ?? '',
        job_title: e?.job_title ?? '',
        hire_date: e?.hire_date ?? '',
        user_id: e?.user_id ? String(e.user_id) : '',
        team_id: e?.team ? String(e.team.id) : '',
        territory_id: e?.territory ? String(e.territory.id) : '',
        is_active: e?.is_active ?? true,
    };
}

export function unitToForm(u: HrUnit | null): UnitFormValues {
    return { name: u?.name ?? '', description: u?.description ?? '', is_active: u?.is_active ?? true };
}

// URL search state (8.7). Validators never throw. The router parses numeric-looking values to numbers, so accept both.
function posInt(v: unknown): number | undefined {
    const n = Number(v);
    return Number.isInteger(n) && n > 0 ? n : undefined;
}

function text(v: unknown): string {
    return typeof v === 'string' || typeof v === 'number' ? String(v).trim().slice(0, 100) : '';
}

export type EmployeesSearch = { q?: string; team_id?: number; territory_id?: number; status?: 'active' | 'inactive'; page?: number };

export function validateEmployeesSearch(raw: Record<string, unknown>): EmployeesSearch {
    const out: EmployeesSearch = {};
    const q = text(raw.q);
    if (q) out.q = q;
    const team = posInt(raw.team_id);
    if (team) out.team_id = team;
    const territory = posInt(raw.territory_id);
    if (territory) out.territory_id = territory;
    if (raw.status === 'active' || raw.status === 'inactive') out.status = raw.status;
    const page = posInt(raw.page);
    if (page && page > 1) out.page = page;
    return out;
}

export type HrUnitsSearch = { kind?: 'territory'; q?: string; status?: 'active' | 'inactive'; page?: number };

export function validateHrUnitsSearch(raw: Record<string, unknown>): HrUnitsSearch {
    const out: HrUnitsSearch = {};
    if (raw.kind === 'territory') out.kind = 'territory';
    const q = text(raw.q);
    if (q) out.q = q;
    if (raw.status === 'active' || raw.status === 'inactive') out.status = raw.status;
    const page = posInt(raw.page);
    if (page && page > 1) out.page = page;
    return out;
}
