import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { useQuoteVersions } from '../api/useQuoteVersions';
import { transitionQuoteErrorMessage, useTransitionQuote } from '../api/useTransitionQuote';
import { QUOTE_ACTION_LABELS, QUOTE_APPROVER_TARGETS, QUOTE_TRANSITIONS, type Quote, type QuoteStatus } from '../schemas/quote';

const rejectSchema = z.object({
    note: z.string().trim().min(1, 'Enter a reason for rejecting this quote.').max(1000, 'Keep it under 1000 characters.'),
});
type RejectValues = z.infer<typeof rejectSchema>;

function when(value: string | null | undefined): string {
    if (!value) return '';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
}

// 10.3b: status actions for a quote. Buttons mirror the server's transition map and permissions; the server
// re-checks everything. Only the latest version of a quote can be moved.
export function QuoteWorkflowBar({ quote, formDirty }: { quote: Quote; formDirty: boolean }) {
    const canManage = usePermission('quotes.manage');
    const canApprove = usePermission('quotes.approve');
    const transition = useTransitionQuote();
    const versions = useQuoteVersions(quote.id);
    const [error, setError] = useState<string | null>(null);
    const [rejectOpen, setRejectOpen] = useState(false);

    const {
        register,
        handleSubmit,
        reset,
        formState: { errors, isSubmitting },
    } = useForm<RejectValues>({ resolver: zodResolver(rejectSchema), defaultValues: { note: '' } });

    const latest = versions.data?.[0] ?? null;
    const isLatest = latest === null || latest.id === quote.id;

    const targets = isLatest
        ? QUOTE_TRANSITIONS[quote.status].filter((to) => (QUOTE_APPROVER_TARGETS.includes(to) ? canApprove : canManage))
        : [];

    async function run(to: QuoteStatus, note?: string): Promise<boolean> {
        setError(null);
        if (to === 'pending_approval' && formDirty) {
            setError('Save your changes before submitting this quote.');
            return false;
        }
        try {
            await transition.mutateAsync({ quote, toStatus: to, note });
            return true;
        } catch (err) {
            setError(transitionQuoteErrorMessage(err));
            return false;
        }
    }

    const onReject = handleSubmit(async (values) => {
        const ok = await run('rejected', values.note);
        if (ok) {
            setRejectOpen(false);
            reset({ note: '' });
        }
    });

    function closeReject() {
        setRejectOpen(false);
        setError(null);
        reset({ note: '' });
    }

    const info: string[] = [];
    if (quote.submitted_at) info.push(`Submitted ${when(quote.submitted_at)}`);
    if (quote.decided_at && (quote.status === 'approved' || quote.status === 'rejected' || quote.status === 'sent' || quote.status === 'accepted' || quote.status === 'declined' || quote.status === 'expired')) {
        info.push(`${quote.status === 'rejected' ? 'Rejected' : 'Approved'} ${when(quote.decided_at)}`);
    }
    if (quote.sent_at) info.push(`Sent ${when(quote.sent_at)}`);

    if (targets.length === 0 && info.length === 0 && !quote.decision_note && !error) return null;

    return (
        <div className="mb-3 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2">
            {(info.length > 0 || quote.decision_note) && (
                <div className="mb-2 text-sm text-[var(--color-ink-muted)]">
                    {info.length > 0 && <p>{info.join(' \u00b7 ')}</p>}
                    {quote.decision_note && <p className="mt-1">Note: {quote.decision_note}</p>}
                </div>
            )}

            {targets.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {targets.map((to) => (
                        <button
                            key={to}
                            type="button"
                            disabled={transition.isPending}
                            onClick={() => {
                                if (to === 'rejected') {
                                    setError(null);
                                    setRejectOpen(true);
                                } else {
                                    void run(to);
                                }
                            }}
                            className={
                                to === 'pending_approval' || to === 'approved'
                                    ? 'rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50'
                                    : `rounded-md border px-3 py-1.5 text-sm disabled:opacity-50 ${to === 'rejected' || to === 'declined' ? 'text-red-700' : ''}`
                            }
                        >
                            {transition.isPending && transition.variables?.toStatus === to ? 'Working...' : QUOTE_ACTION_LABELS[to]}
                        </button>
                    ))}
                </div>
            )}

            {error && !rejectOpen && (
                <p role="alert" className="mt-2 text-sm text-red-700">
                    {error}
                </p>
            )}

            <Modal open={rejectOpen} onClose={closeReject} title="Reject quote">
                <form onSubmit={onReject} noValidate className="flex flex-col gap-3">
                    <div>
                        <label htmlFor="quote-reject-note" className="mb-1 block text-sm font-medium">
                            Reason
                        </label>
                        <textarea
                            id="quote-reject-note"
                            rows={4}
                            aria-invalid={errors.note ? true : undefined}
                            className="w-full rounded-md border px-3 py-2 text-sm"
                            {...register('note')}
                        />
                        {errors.note?.message && (
                            <p role="alert" className="mt-1 text-xs text-red-700">
                                {errors.note.message}
                            </p>
                        )}
                    </div>
                    {error && rejectOpen && (
                        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={closeReject} disabled={isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={isSubmitting}
                            className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                        >
                            {isSubmitting ? 'Rejecting...' : 'Reject quote'}
                        </button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
