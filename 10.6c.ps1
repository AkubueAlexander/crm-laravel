$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

foreach ($p in @(
    'frontend\src\features\invoices\schemas\invoice.ts',
    'frontend\src\features\invoices\api\useInvoiceMutations.ts',
    'frontend\src\features\invoices\components\InvoicesPage.tsx',
    'frontend\src\features\invoices\components\InvoiceDetail.tsx',
    'frontend\src\shared\components\Modal.tsx'
)) { if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing prerequisite: $p" } }

$utf8 = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = [System.IO.Path]::GetDirectoryName($Path)
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $c, $utf8)
}

function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    $p = Join-Path $repo $Path
    $raw = [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8)
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor must occur exactly once in $Path : $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($p, $t, $utf8)
    return 'patched'
}

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $script:report.Add($m) }

# ---------------------------------------------------------------- new files

$paymentSchemaTs = @'
import { z } from 'zod';

// Mirrors RecordPaymentAction::METHODS (10.6a). The server is the authority.
export const PAYMENT_METHODS = ['bank_transfer', 'card', 'cash', 'cheque', 'other'] as const;
export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

export const PAYMENT_METHOD_LABELS: Record<PaymentMethod, string> = {
    bank_transfer: 'Bank transfer',
    card: 'Card',
    cash: 'Cash',
    cheque: 'Cheque',
    other: 'Other',
};

// Mirrors PaymentResource (10.6b). Money is a decimal string. Reversed payments stay in the list.
export const paymentSchema = z.object({
    id: z.number(),
    amount: z.string(),
    method: z.enum(PAYMENT_METHODS),
    paid_on: z.string().nullable(),
    reference: z.string().nullable(),
    note: z.string().nullable(),
    recorded_by: z.number().nullable(),
    voided_at: z.string().nullable(),
    void_reason: z.string().nullable(),
    created_at: z.string().nullable(),
});

export type Payment = z.infer<typeof paymentSchema>;

export function todayIso(): string {
    const d = new Date();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
}

// UX layer only: RecordPaymentAction re-validates everything under the invoice row lock (14.2).
export const paymentFormSchema = z.object({
    amount: z
        .string()
        .trim()
        .regex(/^\d{1,12}(\.\d{1,2})?$/, 'Enter a valid amount (up to 2 decimals).')
        .refine((v) => Number(v) > 0, 'Amount must be greater than zero.'),
    method: z.enum(PAYMENT_METHODS),
    paid_on: z
        .string()
        .min(1, 'Pick a date.')
        .refine((v) => v <= todayIso(), 'The payment date cannot be in the future.'),
    reference: z.string().trim().max(120, 'Keep it under 120 characters.'),
    note: z.string().trim().max(1000, 'Keep it under 1000 characters.'),
});

export type PaymentFormValues = z.infer<typeof paymentFormSchema>;

export const reversePaymentSchema = z.object({
    reason: z.string().trim().min(1, 'Enter a reason for reversing this payment.').max(1000, 'Keep it under 1000 characters.'),
});

export type ReversePaymentValues = z.infer<typeof reversePaymentSchema>;
'@

$paymentMutationsTs = @'
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { invoiceSchema, type Invoice } from '../schemas/invoice';
import type { PaymentFormValues } from '../schemas/payment';
import { invoiceDetailKey, invoicesRootKey } from './useInvoices';

// Field messages from a 422 ({ errors: { amount: [..] } }), first message per field. Empty for any other error.
export function paymentFieldErrors(err: unknown): Record<string, string> {
    if (!isApiError(err) || err.response?.status !== 422) return {};
    const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};
    const out: Record<string, string> = {};
    for (const [field, messages] of Object.entries(errors)) {
        if (Array.isArray(messages) && typeof messages[0] === 'string') out[field] = messages[0];
    }
    return out;
}

// NOT optimistic: the server decides (balance, overpayment, status), and every payment bumps lock_version.
// The response (invoice + payments) is merged into the detail cache, then the whole invoice tree is refetched.
function useApplyInvoice() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return (server: Invoice) => {
        queryClient.setQueryData<Invoice>(invoiceDetailKey(tenant?.id, server.id), (old) => (old ? { ...old, ...server } : old));
        void queryClient.invalidateQueries({ queryKey: invoicesRootKey(tenant?.id) });
    };
}

export type RecordPaymentVars = { invoiceId: number; values: PaymentFormValues };

export function useRecordPayment() {
    const apply = useApplyInvoice();

    return useMutation({
        mutationFn: async ({ invoiceId, values }: RecordPaymentVars): Promise<Invoice> => {
            const { data } = await apiClient.post(`/invoices/${invoiceId}/payments`, {
                amount: values.amount,
                method: values.method,
                paid_on: values.paid_on,
                ...(values.reference ? { reference: values.reference } : {}),
                ...(values.note ? { note: values.note } : {}),
            });
            return invoiceSchema.parse(data);
        },
        onSuccess: apply,
    });
}

export type VoidPaymentVars = { paymentId: number; reason: string };

export function useVoidPayment() {
    const apply = useApplyInvoice();

    return useMutation({
        mutationFn: async ({ paymentId, reason }: VoidPaymentVars): Promise<Invoice> => {
            const { data } = await apiClient.post(`/payments/${paymentId}/void`, { reason });
            return invoiceSchema.parse(data);
        },
        onSuccess: apply,
    });
}
'@

$paymentsSectionTsx = @'
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { paymentFieldErrors, useRecordPayment, useVoidPayment } from '../api/usePaymentMutations';
import type { Invoice } from '../schemas/invoice';
import {
    PAYMENT_METHODS,
    PAYMENT_METHOD_LABELS,
    paymentFormSchema,
    reversePaymentSchema,
    todayIso,
    type Payment,
    type PaymentFormValues,
    type ReversePaymentValues,
} from '../schemas/payment';

const FORM_FIELDS = ['amount', 'method', 'paid_on', 'reference', 'note'] as const;

function blankPayment(balance: string): PaymentFormValues {
    return { amount: balance, method: 'bank_transfer', paid_on: todayIso(), reference: '', note: '' };
}

// 10.6c: payments on an invoice. Buttons mirror invoices.manage and the invoice status; the server re-checks
// everything (overpayment, status, double reversal) and answers with 422 field errors that are shown inline.
export function PaymentsSection({ invoice }: { invoice: Invoice }) {
    const canManage = usePermission('invoices.manage');
    const record = useRecordPayment();
    const voidPayment = useVoidPayment();
    const money = (v: string) => formatMoney(v, invoice.currency);

    const [recordOpen, setRecordOpen] = useState(false);
    const [recordError, setRecordError] = useState<string | null>(null);
    const [reverseTarget, setReverseTarget] = useState<Payment | null>(null);
    const [reverseError, setReverseError] = useState<string | null>(null);

    const recordForm = useForm<PaymentFormValues>({ resolver: zodResolver(paymentFormSchema), defaultValues: blankPayment(invoice.balance) });
    const reverseForm = useForm<ReversePaymentValues>({ resolver: zodResolver(reversePaymentSchema), defaultValues: { reason: '' } });

    const payments = invoice.payments ?? [];
    if (invoice.status === 'draft' && payments.length === 0) return null;

    const canRecord = canManage && invoice.status === 'issued' && Number(invoice.balance) > 0;
    const canReverse = canManage && (invoice.status === 'issued' || invoice.status === 'paid');

    function openRecord() {
        setRecordError(null);
        recordForm.reset(blankPayment(invoice.balance));
        setRecordOpen(true);
    }

    function closeRecord() {
        setRecordOpen(false);
        setRecordError(null);
    }

    const onRecord = recordForm.handleSubmit(async (values) => {
        setRecordError(null);
        try {
            await record.mutateAsync({ invoiceId: invoice.id, values });
            closeRecord();
        } catch (err) {
            const fields = paymentFieldErrors(err);
            let shown = false;
            for (const f of FORM_FIELDS) {
                if (fields[f]) {
                    recordForm.setError(f, { message: fields[f] });
                    shown = true;
                }
            }
            if (!shown) setRecordError(fields.invoice ?? apiErrorMessage(err, 'Could not record the payment. Please try again.'));
        }
    });

    function openReverse(p: Payment) {
        setReverseError(null);
        reverseForm.reset({ reason: '' });
        setReverseTarget(p);
    }

    function closeReverse() {
        setReverseTarget(null);
        setReverseError(null);
    }

    const onReverse = reverseForm.handleSubmit(async (values) => {
        if (!reverseTarget) return;
        setReverseError(null);
        try {
            await voidPayment.mutateAsync({ paymentId: reverseTarget.id, reason: values.reason });
            closeReverse();
        } catch (err) {
            const fields = paymentFieldErrors(err);
            if (fields.reason) reverseForm.setError('reason', { message: fields.reason });
            else setReverseError(fields.payment ?? fields.invoice ?? apiErrorMessage(err, 'Could not reverse the payment. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';

    return (
        <section className="mt-6">
            <div className="mb-2 flex items-center justify-between">
                <h2 className="text-base font-medium text-[var(--color-ink)]">Payments</h2>
                {canRecord && (
                    <button type="button" onClick={openRecord} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                        Record payment
                    </button>
                )}
            </div>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th className="border-b px-3 py-2 text-left font-medium">Date</th>
                            <th className="border-b px-3 py-2 text-left font-medium">Method</th>
                            <th className="border-b px-3 py-2 text-left font-medium">Reference</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Amount</th>
                            <th className="border-b px-3 py-2 text-left font-medium">Status</th>
                            <th className="border-b px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {payments.map((p) => {
                            const reversed = p.voided_at !== null;
                            return (
                                <tr key={p.id} className={reversed ? 'text-[var(--color-ink-muted)]' : ''}>
                                    <td className="border-b px-3 py-2 align-top">{p.paid_on ?? '-'}</td>
                                    <td className="border-b px-3 py-2 align-top">{PAYMENT_METHOD_LABELS[p.method]}</td>
                                    <td className="border-b px-3 py-2 align-top">
                                        <div>{p.reference ?? '-'}</div>
                                        {p.note && <div className="text-xs text-[var(--color-ink-muted)]">{p.note}</div>}
                                    </td>
                                    <td className={`border-b px-3 py-2 text-right align-top ${reversed ? 'line-through' : ''}`}>{money(p.amount)}</td>
                                    <td className="border-b px-3 py-2 align-top">
                                        {reversed ? (
                                            <span className="text-xs">Reversed{p.void_reason ? `: ${p.void_reason}` : ''}</span>
                                        ) : (
                                            <span className="text-xs">Received</span>
                                        )}
                                    </td>
                                    <td className="border-b px-3 py-2 text-right align-top">
                                        {!reversed && canReverse && (
                                            <button type="button" onClick={() => openReverse(p)} className="rounded-md border px-2 py-1 text-xs text-red-700">
                                                Reverse
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                        {payments.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                                    No payments recorded yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <dl className="mt-3 ml-auto grid w-64 grid-cols-2 gap-y-1 text-sm">
                <dt className="text-[var(--color-ink-muted)]">Amount paid</dt>
                <dd className="text-right">{money(invoice.amount_paid)}</dd>
                <dt className="font-medium">Balance due</dt>
                <dd className="text-right font-medium">{money(invoice.balance)}</dd>
            </dl>

            <Modal open={recordOpen} onClose={closeRecord} title="Record payment">
                <form onSubmit={onRecord} noValidate className="flex flex-col gap-3">
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label htmlFor="payment-amount" className="mb-1 block text-sm font-medium">
                                Amount ({invoice.currency})
                            </label>
                            <input id="payment-amount" inputMode="decimal" aria-invalid={recordForm.formState.errors.amount ? true : undefined} className={inputClass} {...recordForm.register('amount')} />
                            {recordForm.formState.errors.amount?.message && (
                                <p role="alert" className={errClass}>
                                    {recordForm.formState.errors.amount.message}
                                </p>
                            )}
                        </div>
                        <div>
                            <label htmlFor="payment-method" className="mb-1 block text-sm font-medium">
                                Method
                            </label>
                            <select id="payment-method" className={inputClass} {...recordForm.register('method')}>
                                {PAYMENT_METHODS.map((m) => (
                                    <option key={m} value={m}>
                                        {PAYMENT_METHOD_LABELS[m]}
                                    </option>
                                ))}
                            </select>
                            {recordForm.formState.errors.method?.message && (
                                <p role="alert" className={errClass}>
                                    {recordForm.formState.errors.method.message}
                                </p>
                            )}
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label htmlFor="payment-date" className="mb-1 block text-sm font-medium">
                                Date received
                            </label>
                            <input id="payment-date" type="date" max={todayIso()} aria-invalid={recordForm.formState.errors.paid_on ? true : undefined} className={inputClass} {...recordForm.register('paid_on')} />
                            {recordForm.formState.errors.paid_on?.message && (
                                <p role="alert" className={errClass}>
                                    {recordForm.formState.errors.paid_on.message}
                                </p>
                            )}
                        </div>
                        <div>
                            <label htmlFor="payment-reference" className="mb-1 block text-sm font-medium">
                                Reference
                            </label>
                            <input id="payment-reference" className={inputClass} {...recordForm.register('reference')} />
                            {recordForm.formState.errors.reference?.message && (
                                <p role="alert" className={errClass}>
                                    {recordForm.formState.errors.reference.message}
                                </p>
                            )}
                        </div>
                    </div>
                    <div>
                        <label htmlFor="payment-note" className="mb-1 block text-sm font-medium">
                            Note
                        </label>
                        <textarea id="payment-note" rows={3} className={inputClass} {...recordForm.register('note')} />
                        {recordForm.formState.errors.note?.message && (
                            <p role="alert" className={errClass}>
                                {recordForm.formState.errors.note.message}
                            </p>
                        )}
                    </div>
                    {recordError && (
                        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {recordError}
                        </p>
                    )}
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={closeRecord} disabled={recordForm.formState.isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                            Cancel
                        </button>
                        <button type="submit" disabled={recordForm.formState.isSubmitting} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                            {recordForm.formState.isSubmitting ? 'Saving...' : 'Record payment'}
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal open={reverseTarget !== null} onClose={closeReverse} title="Reverse payment">
                <form onSubmit={onReverse} noValidate className="flex flex-col gap-3">
                    {reverseTarget && (
                        <p className="text-sm text-[var(--color-ink-muted)]">
                            Reversing {money(reverseTarget.amount)} ({PAYMENT_METHOD_LABELS[reverseTarget.method]}, {reverseTarget.paid_on ?? '-'}). The payment stays in the list, marked reversed.
                        </p>
                    )}
                    <div>
                        <label htmlFor="payment-reverse-reason" className="mb-1 block text-sm font-medium">
                            Reason
                        </label>
                        <textarea
                            id="payment-reverse-reason"
                            rows={4}
                            aria-invalid={reverseForm.formState.errors.reason ? true : undefined}
                            className={inputClass}
                            {...reverseForm.register('reason')}
                        />
                        {reverseForm.formState.errors.reason?.message && (
                            <p role="alert" className={errClass}>
                                {reverseForm.formState.errors.reason.message}
                            </p>
                        )}
                    </div>
                    {reverseError && (
                        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {reverseError}
                        </p>
                    )}
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={closeReverse} disabled={reverseForm.formState.isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                            Cancel
                        </button>
                        <button type="submit" disabled={reverseForm.formState.isSubmitting} className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                            {reverseForm.formState.isSubmitting ? 'Reversing...' : 'Reverse payment'}
                        </button>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
'@

# ---------------------------------------------------------------- patches

$patches = New-Object System.Collections.ArrayList
$schemaFile = 'frontend\src\features\invoices\schemas\invoice.ts'
$detailFile = 'frontend\src\features\invoices\components\InvoiceDetail.tsx'
$pageFile = 'frontend\src\features\invoices\components\InvoicesPage.tsx'

[void]$patches.Add(@{
    Path = $schemaFile
    Anchor = "import { TAX_CATEGORIES } from '@/features/products/schemas/product';"
    Replacement = "import { TAX_CATEGORIES } from '@/features/products/schemas/product';`nimport { paymentSchema } from './payment';"
    Skip = "from './payment';"
})
[void]$patches.Add(@{
    Path = $schemaFile
    Anchor = 'issued_at: z.string().nullable(),'
    Replacement = "amount_paid: z.string(),`n    balance: z.string(),`n    issued_at: z.string().nullable(),"
    Skip = 'amount_paid: z.string(),'
})
[void]$patches.Add(@{
    Path = $schemaFile
    Anchor = 'lines: z.array(invoiceLineSchema).optional(),'
    Replacement = "lines: z.array(invoiceLineSchema).optional(),`n    payments: z.array(paymentSchema).optional(),"
    Skip = 'payments: z.array(paymentSchema)'
})
[void]$patches.Add(@{
    Path = $detailFile
    Anchor = "import { PdfPreview } from '@/shared/components/PdfPreview';"
    Replacement = "import { PdfPreview } from '@/shared/components/PdfPreview';`nimport { PaymentsSection } from './PaymentsSection';"
    Skip = "from './PaymentsSection';"
})
[void]$patches.Add(@{
    Path = $detailFile
    Anchor = '{(invoice.notes || invoice.terms) && ('
    Replacement = "<PaymentsSection invoice={invoice} />`n`n            {(invoice.notes || invoice.terms) && ("
    Skip = '<PaymentsSection invoice={invoice} />'
})
[void]$patches.Add(@{
    Path = $pageFile
    Anchor = "columnHelper.accessor('due_date', {"
    Replacement = "columnHelper.accessor('balance', {`n                header: 'Balance',`n                cell: (info) => (info.row.original.status === 'issued' || info.row.original.status === 'paid' ? formatMoney(info.getValue(), info.row.original.currency) : '-'),`n                enableSorting: false,`n            }),`n            columnHelper.accessor('due_date', {"
    Skip = "columnHelper.accessor('balance', {"
})

# ---------------------------------------------------------------- preflight (nothing written yet)

foreach ($pt in $patches) {
    $p = Join-Path $repo $pt.Path
    $t = [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8).Replace("`r`n", "`n")
    if ($pt.Skip -and $t.Contains($pt.Skip)) { continue }
    $n = Count-Sub $t $pt.Anchor
    if ($n -ne 1) { throw "PREFLIGHT: anchor occurs $n times in $($pt.Path): $($pt.Anchor)" }
}
Say 'Preflight OK.'

# ---------------------------------------------------------------- write + patch

Write-NoBom 'frontend\src\features\invoices\schemas\payment.ts' $paymentSchemaTs
Write-NoBom 'frontend\src\features\invoices\api\usePaymentMutations.ts' $paymentMutationsTs
Write-NoBom 'frontend\src\features\invoices\components\PaymentsSection.tsx' $paymentsSectionTsx
Say 'Wrote 3 new files.'

foreach ($pt in $patches) {
    $res = Patch-Once $pt.Path $pt.Anchor $pt.Replacement $pt.Skip
    Say ("{0}: {1}" -f $pt.Path, $res)
}

# ---------------------------------------------------------------- type-check

$ErrorActionPreference = 'Continue'
Push-Location (Join-Path $repo 'frontend')
$tsc = (& npx.cmd tsc --noEmit 2>&1 | Out-String)
$tscOk = ($LASTEXITCODE -eq 0)
Pop-Location
$ErrorActionPreference = 'Stop'

if ($tscOk) { Say 'tsc --noEmit: OK' } else { Say 'tsc --noEmit: FAILED'; Say $tsc }

Set-Clipboard -Value ($report -join "`r`n")
Say 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): frontend/src/shared/lib/apiClient.ts (path was MISSING, I used its @/shared/lib/apiClient import as other files do), router.tsx, AppShell.tsx, QuoteWorkflowBar.tsx, any other file that builds an Invoice object (tsc will flag them).'
