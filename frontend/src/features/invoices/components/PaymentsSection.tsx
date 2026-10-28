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
