import { useEffect, useState } from 'react';
import { useForm, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useJournalEntry } from '../api/useAccounting';
import { accountingFieldErrors, useReverseJournalEntry } from '../api/useAccountingMutations';
import { amountOrDash, dateOnly, formatCents, formatLedgerAmount, reverseFormSchema, toCents, todayIso, type JournalEntry, type ReverseFormValues } from '../schemas/accounting';

function EntryRef({ id }: { id: number }) {
    const q = useJournalEntry(id);
    return (
        <Link to="/accounting/journal/$entryId" params={{ entryId: String(id) }} className="font-mono underline">
            {q.data?.entry_number ?? `#${id}`}
        </Link>
    );
}

// Reversal: the server posts a new entry with debits and credits swapped; the original is never edited.
function ReverseModal({ open, onClose, entry }: { open: boolean; onClose: () => void; entry: JournalEntry }) {
    const reverse = useReverseJournalEntry();
    const navigate = useNavigate();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<ReverseFormValues>({ resolver: zodResolver(reverseFormSchema), defaultValues: { reason: '', entry_date: todayIso() } });

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset({ reason: '', entry_date: todayIso() });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            const created = await reverse.mutateAsync({ entryId: entry.id, reason: values.reason, entryDate: values.entry_date || undefined });
            onClose();
            void navigate({ to: '/accounting/journal/$entryId', params: { entryId: String(created.id) } });
        } catch (err) {
            const fields422 = accountingFieldErrors(err);
            let shown = false;
            for (const key of ['reason', 'entry_date'] as const) {
                if (fields422[key]) {
                    form.setError(key as Path<ReverseFormValues>, { message: fields422[key] });
                    shown = true;
                }
            }
            if (!shown) setFormError(fields422.entry ?? apiErrorMessage(err, 'Could not reverse the entry. Please try again.'));
        }
    });

    const errors = form.formState.errors;

    return (
        <Modal open={open} onClose={onClose} title={`Reverse ${entry.entry_number}`}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <p className="text-sm text-[var(--color-ink-muted)]">This posts a new entry with debits and credits swapped. The original entry stays as it is.</p>
                <div>
                    <label htmlFor="rev-date" className="mb-1 block text-sm font-medium">
                        Reversal date
                    </label>
                    <input id="rev-date" type="date" aria-invalid={errors.entry_date ? true : undefined} className="w-full rounded-md border px-3 py-2 text-sm" {...form.register('entry_date')} />
                    {errors.entry_date?.message && (
                        <p role="alert" className="mt-1 text-xs text-red-700">
                            {errors.entry_date.message}
                        </p>
                    )}
                </div>
                <div>
                    <label htmlFor="rev-reason" className="mb-1 block text-sm font-medium">
                        Reason
                    </label>
                    <textarea id="rev-reason" rows={3} aria-invalid={errors.reason ? true : undefined} className="w-full rounded-md border px-3 py-2 text-sm" {...form.register('reason')} />
                    {errors.reason?.message && (
                        <p role="alert" className="mt-1 text-xs text-red-700">
                            {errors.reason.message}
                        </p>
                    )}
                </div>
                {formError && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {formError}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} disabled={form.formState.isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                        Cancel
                    </button>
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Reversing...' : 'Reverse entry'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

// 12.1a: journal entry detail (read-only, append-only ledger).
export function JournalEntryDetail() {
    const allowed = usePermission('accounting.view');
    const canManage = usePermission('accounting.manage');
    const params = useParams({ strict: false }) as { entryId?: string };
    const entryId = Number(params.entryId);
    const validId = Number.isInteger(entryId) && entryId > 0;
    const query = useJournalEntry(validId && allowed ? entryId : null);
    const [reverseOpen, setReverseOpen] = useState(false);

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to accounting.</p>;
    if (!validId) return <p className="text-sm text-red-700">Invalid journal entry.</p>;
    if (query.isPending) return <p className="text-sm text-gray-500">Loading...</p>;
    if (query.isError || !query.data) {
        return (
            <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {apiErrorMessage(query.error, "Couldn't load this journal entry.")}
            </p>
        );
    }

    const entry = query.data;
    const lines = entry.lines ?? [];
    let debit = 0;
    let credit = 0;
    for (const l of lines) {
        debit += toCents(Number(l.debit).toFixed(2)) ?? 0;
        credit += toCents(Number(l.credit).toFixed(2)) ?? 0;
    }
    const canReverse = canManage && !entry.reversed_by_entry_id && !entry.reverses_entry_id;

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/accounting" className="text-[var(--color-ink-muted)] underline">
                    Journal
                </Link>
            </div>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h1 className="flex items-center gap-3 text-lg font-medium text-[var(--color-ink)]">
                    <span className="font-mono text-base">{entry.entry_number}</span>
                    {entry.reversed_by_entry_id ? <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Reversed</span> : null}
                    {entry.reverses_entry_id ? <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">Reversal</span> : null}
                </h1>
                {canReverse && (
                    <button type="button" onClick={() => setReverseOpen(true)} className="rounded-md border px-3 py-1.5 text-sm text-red-700">
                        Reverse entry
                    </button>
                )}
            </div>

            <dl className="mb-6 grid max-w-3xl grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Date</dt>
                    <dd>{dateOnly(entry.entry_date)}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Total</dt>
                    <dd>{formatLedgerAmount(entry.total)}</dd>
                </div>
                {entry.reverses_entry_id ? (
                    <div>
                        <dt className="text-[var(--color-ink-muted)]">Reverses</dt>
                        <dd>
                            <EntryRef id={entry.reverses_entry_id} />
                        </dd>
                    </div>
                ) : null}
                {entry.reversed_by_entry_id ? (
                    <div>
                        <dt className="text-[var(--color-ink-muted)]">Reversed by</dt>
                        <dd>
                            <EntryRef id={entry.reversed_by_entry_id} />
                        </dd>
                    </div>
                ) : null}
                {entry.memo && (
                    <div className="col-span-2 sm:col-span-4">
                        <dt className="text-[var(--color-ink-muted)]">Memo</dt>
                        <dd>{entry.memo}</dd>
                    </div>
                )}
            </dl>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th className="border-b px-3 py-2 text-left font-medium">Account</th>
                            <th className="border-b px-3 py-2 text-left font-medium">Description</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Debit</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l, i) => (
                            <tr key={l.id ?? i}>
                                <td className="border-b px-3 py-2 align-top">
                                    <span className="font-mono text-xs">{l.account_code ?? `#${l.account_id}`}</span>
                                    <div className="text-xs text-[var(--color-ink-muted)]">{l.account_name}</div>
                                </td>
                                <td className="border-b px-3 py-2 align-top">{l.description ?? ''}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{amountOrDash(l.debit)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{amountOrDash(l.credit)}</td>
                            </tr>
                        ))}
                        {lines.length === 0 && (
                            <tr>
                                <td colSpan={4} className="px-3 py-6 text-center text-gray-500">
                                    No lines.
                                </td>
                            </tr>
                        )}
                    </tbody>
                    {lines.length > 0 && (
                        <tfoot>
                            <tr>
                                <td colSpan={2} className="px-3 py-2 font-medium">
                                    Totals
                                </td>
                                <td className="px-3 py-2 text-right font-medium">{formatCents(debit)}</td>
                                <td className="px-3 py-2 text-right font-medium">{formatCents(credit)}</td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>

            <ReverseModal open={reverseOpen} onClose={() => setReverseOpen(false)} entry={entry} />
        </div>
    );
}
