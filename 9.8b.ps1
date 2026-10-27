$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw 'artisan not found: wrong repo path' }
Set-Location $repo

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = Split-Path $Path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, ($Content -replace "`r`n", "`n"), $enc)
}

function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $raw = [System.IO.File]::ReadAllText($Path)
    $crlf = $raw.Contains("`r`n")
    $text = $raw -replace "`r`n", "`n"
    if ($text.Contains($Skip)) { return 'skipped' }
    $a = $Anchor -replace "`r`n", "`n"
    $r = $Replacement -replace "`r`n", "`n"
    if ((Count-Sub $text $a) -ne 1) { throw "Anchor not exactly once in $Path : $Anchor" }
    $idx = $text.IndexOf($a, [System.StringComparison]::Ordinal)
    $text = $text.Substring(0, $idx) + $r + $text.Substring($idx + $a.Length)
    if ($crlf) { $text = $text -replace "`n", "`r`n" }
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $text, $enc)
    return 'patched'
}

$router = 'frontend\src\router.tsx'
foreach ($p in @($router, 'frontend\src\shared\components\Modal.tsx', 'frontend\src\shared\schemas\pagination.ts')) {
    if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing prerequisite $p" }
}

$patches = New-Object System.Collections.Generic.List[object]

$patches.Add(@{ Path = $router; Skip = "import { LeadFormsPage }"
Anchor = "import { createAutomationRoutes } from '@/features/marketing/automationRoutes';"
Replacement = @'
import { createAutomationRoutes } from '@/features/marketing/automationRoutes';
import { LeadFormsPage } from '@/features/marketing/components/LeadFormsPage';
'@ })

$patches.Add(@{ Path = $router; Skip = 'const leadFormsRoute'
Anchor = '// 8c.3c: audit log viewer. Filters and page live in the URL (8.7).'
Replacement = @'
// 9.8b: lead capture forms (admin). The public page for a form is /f/{uuid} (9.8c), outside this layout.
const leadFormsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/marketing/forms',
    component: LeadFormsPage,
});

// 8c.3c: audit log viewer. Filters and page live in the URL (8.7).
'@ })

$patches.Add(@{ Path = $router; Skip = '...automationRoutes, leadFormsRoute'
Anchor = '...automationRoutes]),'
Replacement = @'
...automationRoutes, leadFormsRoute]),
'@ })

# PREFLIGHT
foreach ($p in $patches) {
    $t = ([System.IO.File]::ReadAllText((Join-Path $repo $p.Path))) -replace "`r`n", "`n"
    if (-not $t.Contains($p.Skip)) {
        $a = $p.Anchor -replace "`r`n", "`n"
        if ((Count-Sub $t $a) -ne 1) { throw "PREFLIGHT: anchor not exactly once in $($p.Path): $($p.Anchor)" }
    }
}

$base = 'frontend\src\features\marketing'

Write-NoBom "$base\schemas\leadForm.ts" @'
import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// Mirrors LeadFormResource::toArray() (app/Http/Resources/LeadFormResource.php).
export const leadFormFieldSchema = z.object({
  key: z.string(),
  label: z.string(),
  required: z.boolean(),
});

export const leadFormSchema = z.object({
  id: z.number(),
  uuid: z.string(),
  name: z.string(),
  is_active: z.boolean(),
  source: z.string().nullable(),
  success_message: z.string().nullable(),
  fields: z.array(leadFormFieldSchema),
  public_path: z.string(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});
export type LeadForm = z.infer<typeof leadFormSchema>;

export const leadFormsPageSchema = z
  .object({
    data: z.array(leadFormSchema),
    meta: paginationMetaSchema,
  })
  .passthrough();

// GET /lead-forms/catalog: the public lead fields a form may collect. The builder renders from it.
export const leadFormCatalogSchema = z.object({
  data: z.array(z.object({ key: z.string(), label: z.string() })),
  max_fields: z.number().int().positive(),
});
export type LeadFormCatalog = z.infer<typeof leadFormCatalogSchema>;
export type LeadFormCatalogItem = LeadFormCatalog['data'][number];

// ---- Builder form (UX layer only: SaveLeadFormAction + LeadFormDsl on the API are the boundary, 14.2).

export function buildLeadFormSchema(catalog: LeadFormCatalogItem[], maxFields: number) {
  const keys = catalog.map((c) => c.key);

  return z
    .object({
      name: z.string().trim().min(1, 'Name is required').max(160, 'Keep the name under 160 characters'),
      source: z.string().trim().max(100, 'Keep the source under 100 characters'),
      success_message: z.string().trim().max(300, 'Keep the message under 300 characters'),
      is_active: z.boolean(),
      fields: z
        .array(
          z.object({
            key: z.string().refine((k) => keys.includes(k), 'Choose a field'),
            label: z.string().trim().min(1, 'Enter a label').max(60, 'Keep the label under 60 characters'),
            required: z.boolean(),
          }),
        )
        .min(1, 'Add at least one field')
        .max(maxFields, `Use at most ${maxFields} fields`),
    })
    .superRefine((data, ctx) => {
      const seen = new Set<string>();
      data.fields.forEach((field, index) => {
        if (seen.has(field.key)) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            path: ['fields', index, 'key'],
            message: 'This field is already on the form',
          });
        }
        seen.add(field.key);
      });

      const first = data.fields.find((f) => f.key === 'first_name');
      if (!first || !first.required) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['fields'],
          message: 'First name must be on the form and required',
        });
      } else if (!data.fields.some((f) => f.key === 'email' || f.key === 'phone')) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['fields'],
          message: 'Add an email or phone field so you can reach the lead',
        });
      }
    });
}

export type LeadFormValues = z.infer<ReturnType<typeof buildLeadFormSchema>>;

export type LeadFormPayload = {
  name: string;
  is_active: boolean;
  source: string | null;
  success_message: string | null;
  fields: { key: string; label: string; required: boolean }[];
};

export function toLeadFormPayload(values: LeadFormValues): LeadFormPayload {
  const source = values.source.trim();
  const message = values.success_message.trim();
  return {
    name: values.name.trim(),
    is_active: values.is_active,
    source: source === '' ? null : source,
    success_message: message === '' ? null : message,
    fields: values.fields.map((f) => ({ key: f.key, label: f.label.trim(), required: f.required })),
  };
}

export function toLeadFormValues(form: LeadForm | null, catalog: LeadFormCatalogItem[]): LeadFormValues {
  if (form) {
    return {
      name: form.name,
      source: form.source ?? '',
      success_message: form.success_message ?? '',
      is_active: form.is_active,
      fields: form.fields.map((f) => ({ key: f.key, label: f.label, required: f.required })),
    };
  }

  const labelFor = (key: string) => catalog.find((c) => c.key === key)?.label ?? key;
  return {
    name: '',
    source: '',
    success_message: '',
    is_active: true,
    fields: [
      { key: 'first_name', label: labelFor('first_name'), required: true },
      { key: 'email', label: labelFor('email'), required: true },
    ],
  };
}
'@

Write-NoBom "$base\api\leadFormErrors.ts" @'
import { isAxiosError } from 'axios';

export type ParsedApiError = {
  message: string;
  errors: Record<string, string[]>;
  status: number | null;
};

const FALLBACK = 'Something went wrong. Try again.';

/** Reads the API's { message, errors, code } error shape (14.3) without assuming the error is an Axios error. */
export function parseApiError(error: unknown): ParsedApiError {
  if (!isAxiosError(error)) return { message: FALLBACK, errors: {}, status: null };

  const data = error.response?.data as { message?: unknown; errors?: unknown } | undefined;
  const errors: Record<string, string[]> = {};

  if (data && typeof data.errors === 'object' && data.errors !== null) {
    for (const [key, value] of Object.entries(data.errors)) {
      if (Array.isArray(value)) {
        errors[key] = value.filter((v): v is string => typeof v === 'string');
      }
    }
  }

  return {
    message: typeof data?.message === 'string' && data.message !== '' ? data.message : FALLBACK,
    errors,
    status: error.response?.status ?? null,
  };
}
'@

Write-NoBom "$base\api\useLeadForms.ts" @'
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { leadFormsPageSchema } from '../schemas/leadForm';

// 1.2: tenant-namespaced key. Everything for lead forms lives under ['lead-forms', tenantId, ...].
export function useLeadForms(page: number) {
  const { tenant } = useTenant();
  const tenantId = tenant?.id ?? null;

  return useQuery({
    queryKey: ['lead-forms', tenantId, 'list', page],
    enabled: tenantId !== null,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const { data } = await apiClient.get('/lead-forms', { params: { page } });
      return leadFormsPageSchema.parse(data);
    },
  });
}
'@

Write-NoBom "$base\api\useLeadFormCatalog.ts" @'
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { leadFormCatalogSchema } from '../schemas/leadForm';

export function useLeadFormCatalog() {
  const { tenant } = useTenant();
  const tenantId = tenant?.id ?? null;

  return useQuery({
    queryKey: ['lead-forms', tenantId, 'catalog'],
    enabled: tenantId !== null,
    staleTime: 5 * 60 * 1000,
    queryFn: async () => {
      const { data } = await apiClient.get('/lead-forms/catalog');
      return leadFormCatalogSchema.parse(data);
    },
  });
}
'@

Write-NoBom "$base\api\useSaveLeadForm.ts" @'
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { leadFormSchema, type LeadFormPayload } from '../schemas/leadForm';

type SaveVars = { id: number | null; payload: LeadFormPayload };

/** Create (id null) and update share one hook, like the API's one write path. */
export function useSaveLeadForm() {
  const queryClient = useQueryClient();
  const { tenant } = useTenant();
  const tenantId = tenant?.id ?? null;

  return useMutation({
    mutationFn: async ({ id, payload }: SaveVars) => {
      const response =
        id === null ? await apiClient.post('/lead-forms', payload) : await apiClient.put(`/lead-forms/${id}`, payload);
      return leadFormSchema.parse(response.data);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lead-forms', tenantId] }),
  });
}
'@

Write-NoBom "$base\api\useDeleteLeadForm.ts" @'
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';

export function useDeleteLeadForm() {
  const queryClient = useQueryClient();
  const { tenant } = useTenant();
  const tenantId = tenant?.id ?? null;

  return useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/lead-forms/${id}`);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lead-forms', tenantId] }),
  });
}
'@

Write-NoBom "$base\components\LeadFormShareBox.tsx" @'
import { useState } from 'react';
import type { LeadForm } from '../schemas/leadForm';

/** Absolute link to the public page (the API only returns the path; the origin is the app's own). */
export function publicUrl(path: string): string {
  return `${window.location.origin}${path}`;
}

function CopyField({ label, value, multiline }: { label: string; value: string; multiline?: boolean }) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      // Clipboard can be blocked (insecure origin): the field is selectable, so the user can copy by hand.
    }
  }

  return (
    <div className="space-y-1">
      <label className="text-xs font-medium text-[var(--color-ink-muted)]">{label}</label>
      <div className="flex gap-2">
        {multiline ? (
          <textarea
            readOnly
            rows={3}
            value={value}
            onFocus={(e) => e.currentTarget.select()}
            className="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-2 py-1.5 font-mono text-xs"
          />
        ) : (
          <input
            readOnly
            value={value}
            onFocus={(e) => e.currentTarget.select()}
            className="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-2 py-1.5 font-mono text-xs"
          />
        )}
        <button
          type="button"
          onClick={() => void copy()}
          className="shrink-0 self-start rounded-md border border-[var(--color-border)] px-3 py-1.5 text-xs hover:bg-[var(--color-surface)]"
        >
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
    </div>
  );
}

export function LeadFormShareBox({ form }: { form: LeadForm }) {
  const url = publicUrl(form.public_path);
  const embed = `<iframe src="${url}" width="100%" height="520" style="border:0" title="${form.name.replace(/"/g, '&quot;')}"></iframe>`;

  return (
    <section className="space-y-3 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
      <h3 className="text-sm font-medium">Share this form</h3>
      {!form.is_active && (
        <p className="text-xs text-amber-700">This form is paused: the link shows a not-found page until it is active.</p>
      )}
      <CopyField label="Link" value={url} />
      <CopyField label="Embed on your website" value={embed} multiline />
    </section>
  );
}
'@

Write-NoBom "$base\components\LeadFormBuilderDialog.tsx" @'
import { useMemo, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { useLeadFormCatalog } from '../api/useLeadFormCatalog';
import { useSaveLeadForm } from '../api/useSaveLeadForm';
import { parseApiError } from '../api/leadFormErrors';
import {
  buildLeadFormSchema,
  toLeadFormPayload,
  toLeadFormValues,
  type LeadForm,
  type LeadFormCatalogItem,
  type LeadFormValues,
} from '../schemas/leadForm';
import { LeadFormShareBox } from './LeadFormShareBox';

const controlClass =
  'w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-2 py-1.5 text-sm text-[var(--color-ink)] disabled:opacity-60';
const iconButtonClass =
  'rounded-md border border-[var(--color-border)] px-2 py-1 text-xs text-[var(--color-ink-muted)] hover:bg-[var(--color-surface)] disabled:opacity-40';

type DialogProps = { open: boolean; leadForm: LeadForm | null; onClose: () => void };

export function LeadFormBuilderDialog({ open, leadForm, onClose }: DialogProps) {
  return (
    <Modal open={open} onClose={onClose} title={leadForm ? 'Edit lead form' : 'New lead form'}>
      {/* Mounted only while open, so each open starts from a clean form. */}
      {open ? <BuilderBody leadForm={leadForm} onDone={onClose} /> : null}
    </Modal>
  );
}

function BuilderBody({ leadForm, onDone }: { leadForm: LeadForm | null; onDone: () => void }) {
  const catalog = useLeadFormCatalog();

  if (catalog.isPending) return <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>;
  if (catalog.isError) return <p className="text-sm text-red-600">Couldn't load the form builder. Try again.</p>;

  return <BuilderForm leadForm={leadForm} catalog={catalog.data.data} maxFields={catalog.data.max_fields} onDone={onDone} />;
}

type FormProps = {
  leadForm: LeadForm | null;
  catalog: LeadFormCatalogItem[];
  maxFields: number;
  onDone: () => void;
};

function BuilderForm({ leadForm, catalog, maxFields, onDone }: FormProps) {
  const save = useSaveLeadForm();
  const [banner, setBanner] = useState<string | null>(null);
  const schema = useMemo(() => buildLeadFormSchema(catalog, maxFields), [catalog, maxFields]);

  const {
    register,
    control,
    handleSubmit,
    setError,
    getValues,
    formState: { errors },
  } = useForm<LeadFormValues>({
    resolver: zodResolver(schema),
    defaultValues: toLeadFormValues(leadForm, catalog),
  });

  const { fields, append, remove, move } = useFieldArray({ control, name: 'fields' });

  function addField() {
    const used = getValues('fields').map((f) => f.key);
    const next = catalog.find((c) => !used.includes(c.key));
    if (next) append({ key: next.key, label: next.label, required: false });
  }

  function applyServerErrors(error: unknown) {
    const parsed = parseApiError(error);
    const rest: string[] = [];
    let mapped = false;

    for (const [key, messages] of Object.entries(parsed.errors)) {
      const message = messages[0] ?? parsed.message;
      const row = /^fields\.(\d+)\.(key|label)$/.exec(key);

      if (key === 'name' || key === 'source' || key === 'success_message') {
        setError(key, { type: 'server', message });
        mapped = true;
      } else if (row && row[2] === 'key') {
        setError(`fields.${Number(row[1])}.key`, { type: 'server', message });
        mapped = true;
      } else if (row) {
        setError(`fields.${Number(row[1])}.label`, { type: 'server', message });
        mapped = true;
      } else {
        rest.push(message);
      }
    }

    setBanner(rest.length > 0 ? rest.join(' ') : mapped ? null : parsed.message);
  }

  const onSubmit = handleSubmit((values) => {
    setBanner(null);
    save.mutate(
      { id: leadForm?.id ?? null, payload: toLeadFormPayload(values) },
      { onSuccess: onDone, onError: applyServerErrors },
    );
  });

  const rootError = errors.fields?.root?.message ?? errors.fields?.message;

  return (
    <form onSubmit={(e) => void onSubmit(e)} className="space-y-4" noValidate>
      <div className="space-y-1">
        <label htmlFor="lf-name" className="text-sm font-medium">Name</label>
        <input id="lf-name" className={controlClass} maxLength={160} {...register('name')} />
        {errors.name && <p role="alert" className="text-sm text-red-600">{errors.name.message}</p>}
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1">
          <label htmlFor="lf-source" className="text-sm font-medium">Lead source tag</label>
          <input id="lf-source" className={controlClass} maxLength={100} placeholder="website" {...register('source')} />
          {errors.source && <p role="alert" className="text-sm text-red-600">{errors.source.message}</p>}
        </div>
        <label className="flex items-center gap-2 pt-6 text-sm">
          <input type="checkbox" {...register('is_active')} />
          Accepting submissions
        </label>
      </div>

      <div className="space-y-1">
        <label htmlFor="lf-message" className="text-sm font-medium">Thank-you message</label>
        <textarea
          id="lf-message"
          rows={2}
          maxLength={300}
          className={controlClass}
          placeholder="Thanks! We have received your details."
          {...register('success_message')}
        />
        {errors.success_message && <p role="alert" className="text-sm text-red-600">{errors.success_message.message}</p>}
      </div>

      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Fields</legend>
        <ul className="space-y-2">
          {fields.map((field, index) => {
            const rowErrors = errors.fields?.[index];
            return (
              <li key={field.id} className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
                <div className="grid gap-2 sm:grid-cols-[1fr_1.4fr_auto_auto] sm:items-start">
                  <select aria-label={`Field ${index + 1} type`} className={controlClass} {...register(`fields.${index}.key`)}>
                    {catalog.map((c) => (
                      <option key={c.key} value={c.key}>{c.label}</option>
                    ))}
                  </select>
                  <input
                    aria-label={`Field ${index + 1} label`}
                    className={controlClass}
                    maxLength={60}
                    {...register(`fields.${index}.label`)}
                  />
                  <label className="flex items-center gap-1 pt-1.5 text-sm">
                    <input type="checkbox" {...register(`fields.${index}.required`)} />
                    Required
                  </label>
                  <div className="flex gap-1">
                    <button type="button" className={iconButtonClass} aria-label={`Move field ${index + 1} up`} disabled={index === 0} onClick={() => move(index, index - 1)}>Up</button>
                    <button type="button" className={iconButtonClass} aria-label={`Move field ${index + 1} down`} disabled={index === fields.length - 1} onClick={() => move(index, index + 1)}>Down</button>
                    <button type="button" className={iconButtonClass} aria-label={`Remove field ${index + 1}`} disabled={fields.length <= 1} onClick={() => remove(index)}>Remove</button>
                  </div>
                </div>
                {(rowErrors?.key?.message ?? rowErrors?.label?.message) && (
                  <p role="alert" className="mt-2 text-sm text-red-600">{rowErrors?.key?.message ?? rowErrors?.label?.message}</p>
                )}
              </li>
            );
          })}
        </ul>
        {rootError && <p role="alert" className="text-sm text-red-600">{rootError}</p>}
        <button type="button" onClick={addField} disabled={fields.length >= maxFields} className={iconButtonClass}>
          Add field
        </button>
      </fieldset>

      {leadForm && <LeadFormShareBox form={leadForm} />}

      {banner && <p role="alert" className="text-sm text-red-600">{banner}</p>}

      <div className="flex justify-end gap-2">
        <button type="button" onClick={onDone} className="rounded-md border border-[var(--color-border)] px-4 py-2 text-sm">
          Cancel
        </button>
        <button
          type="submit"
          disabled={save.isPending}
          className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
        >
          {save.isPending ? 'Saving...' : leadForm ? 'Save changes' : 'Create form'}
        </button>
      </div>
    </form>
  );
}
'@

Write-NoBom "$base\components\LeadFormsPage.tsx" @'
import { useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { usePermission } from '@/shared/hooks/usePermission';
import { useLeadForms } from '../api/useLeadForms';
import { useDeleteLeadForm } from '../api/useDeleteLeadForm';
import { parseApiError } from '../api/leadFormErrors';
import type { LeadForm } from '../schemas/leadForm';
import { LeadFormBuilderDialog } from './LeadFormBuilderDialog';
import { publicUrl } from './LeadFormShareBox';

const columnHelper = createColumnHelper<LeadForm>();

// 9.8b: container owns the queries and "which dialog is open" (8.1/8.2). Display-only permission: the Actions
// re-check marketing.manage on the API (2.3).
export function LeadFormsPage() {
  const canManage = usePermission('marketing.manage');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<LeadForm | null>(null);
  const [creating, setCreating] = useState(false);
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const { data, isPending, isFetching, isError } = useLeadForms(page);
  const deleteForm = useDeleteLeadForm();

  async function copyLink(form: LeadForm) {
    try {
      await navigator.clipboard.writeText(publicUrl(form.public_path));
      setCopiedId(form.id);
      window.setTimeout(() => setCopiedId((current) => (current === form.id ? null : current)), 1500);
    } catch {
      setEditing(form); // the dialog shows the link in a selectable field
    }
  }

  function remove(form: LeadForm) {
    if (!confirm(`Delete the form "${form.name}"? Its public link will stop working. Leads it captured are kept.`)) return;
    setActionError(null);
    deleteForm.mutate(form.id, { onError: (error) => setActionError(parseApiError(error).message) });
  }

  const columns = useMemo(
    () => [
      columnHelper.accessor('name', { header: 'Name' }),
      columnHelper.accessor('is_active', {
        header: 'Status',
        cell: (i) => (
          <span className={i.getValue() ? 'text-green-700' : 'text-[var(--color-ink-muted)]'}>
            {i.getValue() ? 'Active' : 'Paused'}
          </span>
        ),
      }),
      columnHelper.accessor('source', { header: 'Source', cell: (i) => i.getValue() ?? '-' }),
      columnHelper.accessor((row) => row.fields.length, { id: 'fields', header: 'Fields' }),
      columnHelper.accessor('created_at', {
        header: 'Created',
        cell: (i) => (i.getValue() ? new Date(i.getValue()!).toLocaleDateString() : '-'),
      }),
      columnHelper.display({
        id: 'actions',
        header: '',
        cell: (info) => {
          const form = info.row.original;
          return (
            <div className="flex gap-3 text-sm">
              <button onClick={() => void copyLink(form)} className="text-gray-700 hover:underline">
                {copiedId === form.id ? 'Copied' : 'Copy link'}
              </button>
              {canManage && (
                <>
                  <button onClick={() => setEditing(form)} className="text-indigo-600 hover:underline">Edit</button>
                  <button onClick={() => remove(form)} className="text-red-600 hover:underline">Delete</button>
                </>
              )}
            </div>
          );
        },
      }),
    ],
    // copyLink/remove only use stable setters and the mutation object.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [canManage, copiedId, deleteForm],
  );

  const table = useReactTable({
    data: data?.data ?? [],
    columns,
    manualPagination: true,
    getCoreRowModel: getCoreRowModel(),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold">Lead forms</h1>
          <p className="text-sm text-[var(--color-ink-muted)]">Capture leads from your website. Each submission becomes a new lead.</p>
        </div>
        {canManage && (
          <button onClick={() => setCreating(true)} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">
            New form
          </button>
        )}
      </div>

      {actionError && <p role="alert" className="text-sm text-red-600">{actionError}</p>}
      {isError ? (
        <p className="text-sm text-red-600">Couldn't load lead forms. Try refreshing.</p>
      ) : (
        <>
          <table className="w-full text-left text-sm">
            <thead>
              {table.getHeaderGroups().map((hg) => (
                <tr key={hg.id} className="border-b">
                  {hg.headers.map((header) => (
                    <th key={header.id} className="py-2 pr-4 font-medium">
                      {flexRender(header.column.columnDef.header, header.getContext())}
                    </th>
                  ))}
                </tr>
              ))}
            </thead>
            <tbody>
              {isPending ? (
                <tr><td colSpan={columns.length} className="py-4 text-center text-[var(--color-ink-muted)]">Loading...</td></tr>
              ) : table.getRowModel().rows.length === 0 ? (
                <tr><td colSpan={columns.length} className="py-4 text-center text-[var(--color-ink-muted)]">No lead forms yet.</td></tr>
              ) : (
                table.getRowModel().rows.map((row) => (
                  <tr key={row.id} className="border-b last:border-0">
                    {row.getVisibleCells().map((cell) => (
                      <td key={cell.id} className="py-2 pr-4">{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>
                    ))}
                  </tr>
                ))
              )}
            </tbody>
          </table>

          {data?.meta && (
            <div className="flex items-center justify-between text-sm">
              <span className="text-[var(--color-ink-muted)]">
                {data.meta.total} form{data.meta.total === 1 ? '' : 's'}
                {isFetching ? ' - refreshing...' : ''}
              </span>
              <div className="flex gap-2">
                <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={data.meta.current_page <= 1} className="rounded-md border px-2 py-1 disabled:opacity-50">Previous</button>
                <button onClick={() => setPage((p) => p + 1)} disabled={data.meta.current_page >= data.meta.last_page} className="rounded-md border px-2 py-1 disabled:opacity-50">Next</button>
              </div>
            </div>
          )}
        </>
      )}

      <LeadFormBuilderDialog
        open={creating || editing !== null}
        leadForm={editing}
        onClose={() => {
          setCreating(false);
          setEditing(null);
        }}
      />
    </div>
  );
}
'@

foreach ($p in $patches) { $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip; Write-Host "$($p.Path): $res" }

$ErrorActionPreference = 'Continue'
Push-Location (Join-Path $repo 'frontend')
$tsc = (& npx.cmd tsc --noEmit 2>&1 | Out-String)
$tscCode = $LASTEXITCODE
Pop-Location
$ErrorActionPreference = 'Stop'

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$s) { $report.Add($s); Write-Host $s }
Say "9.8b done. tsc exit code: $tscCode"
if ($tscCode -ne 0) { Say $tsc }
Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): MarketingTabs.tsx (no Lead forms tab yet: open /marketing/forms by URL), useTenant/usePermission internals (tenant.id assumed), Modal children typing, the public /f/{uuid} page (9.8c).'
