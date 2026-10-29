import { useState } from 'react';
import { useFieldArray, useForm, useWatch, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, useNavigate } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useLedgerAccounts } from '../api/useAccounting';
import { accountingFieldErrors, usePostJournalEntry } from '../api/useAccountingMutations';
import { emptyJournalLine, formatCents, journalFormSchema, toCents, todayIso, type JournalFormValues } from '../schemas/accounting';

const HEADER_FIELDS = ['entry_date', 'memo'] as const;
const LINE_FIELD_RE = /^lines\.(\d+)\.(account_id|description|debit|credit)$/;

// 12.1a: manual journal entry. useFieldArray lines; live totals and an out-of-balance indicator.
// Not optimistic: the server numbers the entry and re-checks debits == credits.
export function JournalEntryBuilder() {
    const canManage = usePermission('accounting.manage');
    const navigate = useNavigate();
    const accounts = useLedgerAccounts(canManage);
    const post = usePostJournalEntry();
    const [formError, setFormError] = useState<string | null>(null);

    const form = useForm<JournalFormValues>({
        resolver: zodResolver(journalFormSchema),
        defaultValues: { entry_date: todayIso(), memo: '', lines: [emptyJournalLine(), emptyJournalLine()] },
    });
    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'lines' });
    const watched = useWatch({ control: form.control, name: 'lines' });

    let debit = 0;
    let credit = 0;
    let invalid = false;
    for (const l of watched ?? []) {
        const d = toCents(l?.debit ?? '');
        const c = toCents(l?.credit ?? '');
        if (d === null || c === null) {
            invalid = true;
            continue;
        }
        debit += d;
        credit += c;
    }
    const diff = debit - credit;

    if (!canManage) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to post journal entries.</p>;

    const accountOptions = (accounts.data ?? [])
        .filter((a) => a.is_active)
        .sort((a, b) => a.code.localeCompare(b.code))
        .map((a) => ({ id: String(a.id), label: `${a.code} - ${a.name}` }));

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            const saved = await post.mutateAsync(values);
            void navigate({ to: '/accounting/journal/$entryId', params: { entryId: String(saved.id) } });
        } catch (err) {
            const fields422 = accountingFieldErrors(err);
            let shown = false;
            for (const [key, message] of Object.entries(fields422)) {
                if ((HEADER_FIELDS as readonly string[]).includes(key) || LINE_FIELD_RE.test(key)) {
                    form.setError(key as Path<JournalFormValues>, { message });
                    shown = true;
                }
            }
            if (!shown) setFormError(fields422.lines ?? apiErrorMessage(err, 'Could not post the entry. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';
    const errors = form.formState.errors;
    const linesErr = errors.lines as unknown as { message?: string; root?: { message?: string } } | undefined;
    const linesMessage = linesErr?.root?.message ?? linesErr?.message;

    let indicator: { text: string; className: string };
    if (invalid) indicator = { text: 'Check the amounts', className: 'text-red-700' };
    else if (debit === 0 && credit === 0) indicator = { text: 'Enter amounts', className: 'text-[var(--color-ink-muted)]' };
    else if (diff === 0) indicator = { text: 'Balanced', className: 'text-green-700' };
    else indicator = { text: `Out of balance by ${formatCents(Math.abs(diff))} (${diff > 0 ? 'more debits' : 'more credits'})`, className: 'text-red-700' };

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/accounting" className="text-[var(--color-ink-muted)] underline">
                    Journal
                </Link>
            </div>
            <h1 className="mb-4 text-lg font-medium text-[var(--color-ink)]">New journal entry</h1>

            <form onSubmit={onSubmit} noValidate className="flex max-w-4xl flex-col gap-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label htmlFor="je-date" className="mb-1 block text-sm font-medium">
                            Date
                        </label>
                        <input id="je-date" type="date" aria-invalid={errors.entry_date ? true : undefined} className={inputClass} {...form.register('entry_date')} />
                        {errors.entry_date?.message && (
                            <p role="alert" className={errClass}>
                                {errors.entry_date.message}
                            </p>
                        )}
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="je-memo" className="mb-1 block text-sm font-medium">
                            Memo
                        </label>
                        <input id="je-memo" aria-invalid={errors.memo ? true : undefined} className={inputClass} {...form.register('memo')} />
                        {errors.memo?.message && (
                            <p role="alert" className={errClass}>
                                {errors.memo.message}
                            </p>
                        )}
                    </div>
                </div>

                <section>
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-base font-medium text-[var(--color-ink)]">Lines</h2>
                        <button type="button" onClick={() => append(emptyJournalLine())} disabled={fields.length >= 100} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-40">
                            Add line
                        </button>
                    </div>
                    {accounts.isSuccess && accountOptions.length === 0 && (
                        <p className="mb-2 text-xs text-[var(--color-ink-muted)]">
                            No active accounts yet.{' '}
                            <Link to="/accounting/accounts" className="underline">
                                Set up the chart of accounts
                            </Link>
                            .
                        </p>
                    )}
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr>
                                    <th className="border-b px-2 py-2 text-left font-medium">Account</th>
                                    <th className="border-b px-2 py-2 text-left font-medium">Description</th>
                                    <th className="border-b px-2 py-2 text-left font-medium">Debit</th>
                                    <th className="border-b px-2 py-2 text-left font-medium">Credit</th>
                                    <th className="border-b px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {fields.map((field, index) => {
                                    const rowErrors = errors.lines?.[index];
                                    return (
                                        <tr key={field.id}>
                                            <td className="border-b px-2 py-2 align-top">
                                                <select aria-label={`Account for line ${index + 1}`} aria-invalid={rowErrors?.account_id ? true : undefined} className={inputClass} {...form.register(`lines.${index}.account_id`)}>
                                                    <option value="">Choose an account...</option>
                                                    {accountOptions.map((o) => (
                                                        <option key={o.id} value={o.id}>
                                                            {o.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {rowErrors?.account_id?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.account_id.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 align-top">
                                                <input aria-label={`Description for line ${index + 1}`} aria-invalid={rowErrors?.description ? true : undefined} className={inputClass} {...form.register(`lines.${index}.description`)} />
                                                {rowErrors?.description?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.description.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 align-top">
                                                <input aria-label={`Debit for line ${index + 1}`} inputMode="decimal" aria-invalid={rowErrors?.debit ? true : undefined} className="w-28 rounded-md border px-3 py-2 text-sm" {...form.register(`lines.${index}.debit`)} />
                                                {rowErrors?.debit?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.debit.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 align-top">
                                                <input aria-label={`Credit for line ${index + 1}`} inputMode="decimal" aria-invalid={rowErrors?.credit ? true : undefined} className="w-28 rounded-md border px-3 py-2 text-sm" {...form.register(`lines.${index}.credit`)} />
                                                {rowErrors?.credit?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.credit.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 text-right align-top">
                                                <button type="button" onClick={() => remove(index)} disabled={fields.length <= 2} className="rounded-md border px-2 py-1 text-xs text-red-700 disabled:opacity-40">
                                                    Remove
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <dl className="mt-3 ml-auto grid w-72 grid-cols-2 gap-y-1 text-sm">
                        <dt className="font-medium">Total debits</dt>
                        <dd className="text-right font-medium">{formatCents(debit)}</dd>
                        <dt className="font-medium">Total credits</dt>
                        <dd className="text-right font-medium">{formatCents(credit)}</dd>
                    </dl>
                    <p className={`mt-1 text-right text-sm font-medium ${indicator.className}`}>{indicator.text}</p>
                    {linesMessage && (
                        <p role="alert" className="mt-1 text-right text-xs text-red-700">
                            {linesMessage}
                        </p>
                    )}
                </section>

                {formError && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {formError}
                    </p>
                )}

                <div className="flex justify-end gap-2">
                    <Link to="/accounting" className="rounded-md border px-3 py-1.5 text-sm">
                        Cancel
                    </Link>
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Posting...' : 'Post entry'}
                    </button>
                </div>
            </form>
        </div>
    );
}
