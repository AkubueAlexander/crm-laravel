import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useParams } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { PdfPreview } from '@/shared/components/PdfPreview';
import { PaymentsSection } from './PaymentsSection';
import { useInvoice } from '../api/useInvoices';
import { transitionInvoiceErrorMessage, useTransitionInvoice } from '../api/useInvoiceMutations';
import { INVOICE_ACTION_LABELS, INVOICE_STATUS_LABELS, INVOICE_TRANSITIONS, type Invoice, type InvoiceStatus } from '../schemas/invoice';

const voidSchema = z.object({
    note: z.string().trim().min(1, 'Enter a reason for voiding this invoice.').max(1000, 'Keep it under 1000 characters.'),
});
type VoidValues = z.infer<typeof voidSchema>;

function when(value: string | null | undefined): string {
    if (!value) return '';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
}

// 10.4c: status actions. Buttons mirror the server's transition map and the invoices.manage permission; the
// server re-checks everything and answers a stale lock_version with 409 + the current invoice.
function InvoiceWorkflowBar({ invoice }: { invoice: Invoice }) {
    const canManage = usePermission('invoices.manage');
    const transition = useTransitionInvoice();
    const [error, setError] = useState<string | null>(null);
    const [voidOpen, setVoidOpen] = useState(false);

    const {
        register,
        handleSubmit,
        reset,
        formState: { errors, isSubmitting },
    } = useForm<VoidValues>({ resolver: zodResolver(voidSchema), defaultValues: { note: '' } });

    const targets = canManage ? INVOICE_TRANSITIONS[invoice.status] : [];

    async function run(to: InvoiceStatus, note?: string): Promise<boolean> {
        setError(null);
        try {
            await transition.mutateAsync({ invoice, toStatus: to, note });
            return true;
        } catch (err) {
            setError(transitionInvoiceErrorMessage(err));
            return false;
        }
    }

    const onVoid = handleSubmit(async (values) => {
        const ok = await run('void', values.note);
        if (ok) {
            setVoidOpen(false);
            reset({ note: '' });
        }
    });

    function closeVoid() {
        setVoidOpen(false);
        setError(null);
        reset({ note: '' });
    }

    if (targets.length === 0 && !error) return null;

    return (
        <div className="mb-3 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2">
            {targets.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {targets.map((to) => (
                        <button
                            key={to}
                            type="button"
                            disabled={transition.isPending}
                            onClick={() => {
                                if (to === 'void') {
                                    setError(null);
                                    setVoidOpen(true);
                                } else {
                                    void run(to);
                                }
                            }}
                            className={
                                to === 'issued' || to === 'paid'
                                    ? 'rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50'
                                    : 'rounded-md border px-3 py-1.5 text-sm text-red-700 disabled:opacity-50'
                            }
                        >
                            {transition.isPending && transition.variables?.toStatus === to ? 'Working...' : INVOICE_ACTION_LABELS[to]}
                        </button>
                    ))}
                </div>
            )}

            {error && !voidOpen && (
                <p role="alert" className="mt-2 text-sm text-red-700">
                    {error}
                </p>
            )}

            <Modal open={voidOpen} onClose={closeVoid} title="Void invoice">
                <form onSubmit={onVoid} noValidate className="flex flex-col gap-3">
                    <div>
                        <label htmlFor="invoice-void-note" className="mb-1 block text-sm font-medium">
                            Reason
                        </label>
                        <textarea
                            id="invoice-void-note"
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
                    {error && voidOpen && (
                        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={closeVoid} disabled={isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={isSubmitting}
                            className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                        >
                            {isSubmitting ? 'Voiding...' : 'Void invoice'}
                        </button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}

function InvoiceView({ invoice }: { invoice: Invoice }) {
    const lines = invoice.lines ?? [];
    const money = (v: string) => formatMoney(v, invoice.currency);

    return (
        <div className="max-w-5xl">
            <div className="mb-4 flex items-center gap-3">
                <Link to="/invoices" search={{}} className="text-sm text-[var(--color-ink-muted)] underline">
                    Invoices
                </Link>
                <h1 className="text-lg font-medium text-[var(--color-ink)]">{invoice.invoice_number}</h1>
                <span className="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                    {INVOICE_STATUS_LABELS[invoice.status]}
                </span>
            </div>

            <InvoiceWorkflowBar invoice={invoice} />
            <PdfPreview url={`/api/v1/invoices/${invoice.id}/pdf`} />

            <dl className="mb-4 grid grid-cols-2 gap-x-6 gap-y-2 text-sm md:grid-cols-4">
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Title</dt>
                    <dd className="font-medium">{invoice.title}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Issue date</dt>
                    <dd>{invoice.issue_date ?? '-'}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Due date</dt>
                    <dd>{invoice.due_date ?? `${invoice.payment_terms_days} days after issue`}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Quote</dt>
                    <dd>
                        {invoice.quote_id !== null ? (
                            <Link to="/quotes/$quoteId" params={{ quoteId: String(invoice.quote_id) }} className="underline">
                                View quote
                            </Link>
                        ) : (
                            '-'
                        )}
                    </dd>
                </div>
            </dl>

            {(invoice.paid_at || invoice.voided_at) && (
                <p className="mb-3 text-sm text-[var(--color-ink-muted)]">
                    {invoice.paid_at && `Paid ${when(invoice.paid_at)}`}
                    {invoice.voided_at && `Voided ${when(invoice.voided_at)}`}
                    {invoice.void_reason && ` - ${invoice.void_reason}`}
                </p>
            )}

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th className="border-b px-3 py-2 text-left font-medium">Item</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Qty</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Unit price</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Disc %</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Tax</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l) => (
                            <tr key={l.id}>
                                <td className="border-b px-3 py-2 align-top">
                                    <div className="font-medium">{l.name}</div>
                                    {l.sku && <div className="font-mono text-xs text-[var(--color-ink-muted)]">{l.sku}</div>}
                                    {l.description && <div className="text-xs text-[var(--color-ink-muted)]">{l.description}</div>}
                                </td>
                                <td className="border-b px-3 py-2 text-right align-top">{String(Number(l.quantity))}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{money(l.unit_price)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{String(Number(l.discount_percent))}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{money(l.tax_amount)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{money(l.line_total)}</td>
                            </tr>
                        ))}
                        {lines.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                                    No lines.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <dl className="mt-4 ml-auto grid w-64 grid-cols-2 gap-y-1 text-sm">
                <dt className="text-[var(--color-ink-muted)]">Subtotal</dt>
                <dd className="text-right">{money(invoice.subtotal)}</dd>
                <dt className="text-[var(--color-ink-muted)]">Tax</dt>
                <dd className="text-right">{money(invoice.tax_total)}</dd>
                <dt className="font-medium">Total</dt>
                <dd className="text-right font-medium">{money(invoice.total)}</dd>
            </dl>

            <PaymentsSection invoice={invoice} />

            {(invoice.notes || invoice.terms) && (
                <div className="mt-6 grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
                    {invoice.notes && (
                        <div>
                            <h2 className="mb-1 font-medium">Notes</h2>
                            <p className="whitespace-pre-wrap text-[var(--color-ink-muted)]">{invoice.notes}</p>
                        </div>
                    )}
                    {invoice.terms && (
                        <div>
                            <h2 className="mb-1 font-medium">Terms</h2>
                            <p className="whitespace-pre-wrap text-[var(--color-ink-muted)]">{invoice.terms}</p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

export function InvoiceDetail() {
    const viewAllowed = usePermission('invoices.view');
    const { invoiceId } = useParams({ strict: false }) as { invoiceId?: string };
    const id = invoiceId === undefined ? null : Number(invoiceId);
    const validId = id !== null && Number.isInteger(id) && id > 0 ? id : null;
    const detail = useInvoice(validId);

    if (!viewAllowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to invoices.</p>;
    }

    if (validId === null) return <p className="text-sm text-[var(--color-ink-muted)]">Invoice not found.</p>;

    if (detail.isPending) return <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>;

    if (detail.isError) {
        return (
            <div className="flex flex-col gap-3">
                <p role="alert" className="text-sm text-red-700">
                    {apiErrorMessage(detail.error, 'Could not load the invoice.')}
                </p>
                <Link to="/invoices" search={{}} className="text-sm underline">
                    Back to invoices
                </Link>
            </div>
        );
    }

    return <InvoiceView key={detail.data.id} invoice={detail.data} />;
}
