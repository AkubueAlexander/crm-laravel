import { useEffect, useState } from 'react';
import { useFieldArray, useForm, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, useParams } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { formatQty } from '@/features/inventory/schemas/inventory';
import { usePurchaseOrder } from '../api/usePurchasing';
import { purchasingErrorMessage, purchasingFieldErrors, useReceivePurchaseOrder, useTransitionPurchaseOrder } from '../api/usePurchasingMutations';
import {
    PO_STATUS_CLASSES,
    PO_STATUS_LABELS,
    PO_TRANSITIONS,
    cancelFormSchema,
    receiveFormSchema,
    type CancelFormValues,
    type PoStatus,
    type PurchaseOrder,
    type ReceiveFormValues,
} from '../schemas/purchasing';

const RECEIPT_ERROR_RE = /^receipts\.(\d+)\.(quantity|line_id)$/;

function receiveRows(po: PurchaseOrder): ReceiveFormValues['rows'] {
    return (po.lines ?? [])
        .filter((l) => Number(l.remaining) > 0)
        .map((l) => ({ line_id: l.id, label: `${l.sku ?? '?'} - ${l.name ?? ''}`, unit: l.unit ?? '', remaining: l.remaining, quantity: l.remaining }));
}

// Receive goods: one row per line that still has something to receive, prefilled with the remaining quantity.
// Not optimistic: the server writes the ledger receipts and decides the new status.
function ReceiveModal({ open, onClose, po }: { open: boolean; onClose: () => void; po: PurchaseOrder }) {
    const receive = useReceivePurchaseOrder();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<ReceiveFormValues>({ resolver: zodResolver(receiveFormSchema), defaultValues: { note: '', rows: receiveRows(po) } });
    const { fields } = useFieldArray({ control: form.control, name: 'rows' });

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset({ note: '', rows: receiveRows(po) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        const rowIndexes: number[] = [];
        const receipts: { line_id: number; quantity: string }[] = [];
        values.rows.forEach((r, i) => {
            if (r.quantity !== '') {
                rowIndexes.push(i);
                receipts.push({ line_id: r.line_id, quantity: r.quantity });
            }
        });

        try {
            await receive.mutateAsync({ poId: po.id, lockVersion: po.lock_version, receipts, note: values.note });
            onClose();
        } catch (err) {
            const fields422 = purchasingFieldErrors(err);
            let shown = false;
            for (const [key, message] of Object.entries(fields422)) {
                const m = RECEIPT_ERROR_RE.exec(key);
                const row = m ? rowIndexes[Number(m[1])] : undefined;
                if (row !== undefined) {
                    form.setError(`rows.${row}.quantity` as Path<ReceiveFormValues>, { message });
                    shown = true;
                }
            }
            if (!shown) setFormError(fields422.lines ?? fields422.po ?? fields422.receipts ?? purchasingErrorMessage(err, 'Could not receive the goods. Please try again.'));
        }
    });

    const errors = form.formState.errors;

    return (
        <Modal open={open} onClose={onClose} title={`Receive goods - ${po.po_number}`}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <p className="text-sm text-[var(--color-ink-muted)]">Enter what arrived. Leave a line empty to receive nothing for it. Stock is updated as a ledger receipt referencing {po.po_number}.</p>
                {fields.map((field, index) => (
                    <div key={field.id}>
                        <label htmlFor={`recv-${index}`} className="mb-1 block text-sm font-medium">
                            {field.label} <span className="font-normal text-[var(--color-ink-muted)]">(remaining {formatQty(field.remaining)} {field.unit})</span>
                        </label>
                        <input id={`recv-${index}`} inputMode="decimal" aria-invalid={errors.rows?.[index]?.quantity ? true : undefined} className="w-full rounded-md border px-3 py-2 text-sm" {...form.register(`rows.${index}.quantity`)} />
                        {errors.rows?.[index]?.quantity?.message && (
                            <p role="alert" className="mt-1 text-xs text-red-700">
                                {errors.rows[index]?.quantity?.message}
                            </p>
                        )}
                    </div>
                ))}
                <div>
                    <label htmlFor="recv-note" className="mb-1 block text-sm font-medium">
                        Note
                    </label>
                    <textarea id="recv-note" rows={2} className="w-full rounded-md border px-3 py-2 text-sm" {...form.register('note')} />
                    {errors.note?.message && (
                        <p role="alert" className="mt-1 text-xs text-red-700">
                            {errors.note.message}
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
                    <button type="submit" disabled={form.formState.isSubmitting || fields.length === 0} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Receiving...' : 'Receive goods'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function CancelModal({ open, onClose, onConfirm }: { open: boolean; onClose: () => void; onConfirm: (reason: string) => Promise<boolean> }) {
    const form = useForm<CancelFormValues>({ resolver: zodResolver(cancelFormSchema), defaultValues: { reason: '' } });

    useEffect(() => {
        if (open) form.reset({ reason: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const onSubmit = form.handleSubmit(async (values) => {
        if (await onConfirm(values.reason)) onClose();
    });

    return (
        <Modal open={open} onClose={onClose} title="Cancel purchase order">
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <div>
                    <label htmlFor="po-cancel-reason" className="mb-1 block text-sm font-medium">
                        Reason
                    </label>
                    <textarea id="po-cancel-reason" rows={4} aria-invalid={form.formState.errors.reason ? true : undefined} className="w-full rounded-md border px-3 py-2 text-sm" {...form.register('reason')} />
                    {form.formState.errors.reason?.message && (
                        <p role="alert" className="mt-1 text-xs text-red-700">
                            {form.formState.errors.reason.message}
                        </p>
                    )}
                </div>
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} disabled={form.formState.isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                        Keep it
                    </button>
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Cancelling...' : 'Cancel purchase order'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

// 11.2c: PO detail. Status buttons follow the 3.0 pattern (optimistic, 409 -> reconcile to the server's copy);
// "Receive goods" is its own mutation because it moves stock.
export function PurchaseOrderDetail() {
    const allowed = usePermission('inventory.view');
    const canManage = usePermission('inventory.manage');
    const params = useParams({ strict: false }) as { poId?: string };
    const poId = Number(params.poId);
    const validId = Number.isInteger(poId) && poId > 0;

    const query = usePurchaseOrder(validId && allowed ? poId : null);
    const transition = useTransitionPurchaseOrder();
    const [cancelOpen, setCancelOpen] = useState(false);
    const [receiveOpen, setReceiveOpen] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to purchase orders.</p>;
    if (!validId) return <p className="text-sm text-red-700">Invalid purchase order.</p>;
    if (query.isPending) return <p className="text-sm text-gray-500">Loading...</p>;
    if (query.isError || !query.data) {
        return (
            <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {apiErrorMessage(query.error, "Couldn't load this purchase order.")}
            </p>
        );
    }

    const po = query.data;
    const lines = po.lines ?? [];
    const money = (v: string) => formatMoney(v, po.currency);
    const canReceive = canManage && (po.status === 'ordered' || po.status === 'partially_received');

    async function go(toStatus: PoStatus, note?: string): Promise<boolean> {
        setActionError(null);
        try {
            await transition.mutateAsync({ poId: po.id, toStatus, lockVersion: po.lock_version, note });
            return true;
        } catch (err) {
            setActionError(purchasingErrorMessage(err, 'Could not update the purchase order.'));
            return false;
        }
    }

    const nextStatuses = PO_TRANSITIONS[po.status];

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/purchase-orders" className="text-[var(--color-ink-muted)] underline">
                    Purchase orders
                </Link>
            </div>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h1 className="flex items-center gap-3 text-lg font-medium text-[var(--color-ink)]">
                    <span className="font-mono text-base">{po.po_number}</span>
                    <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${PO_STATUS_CLASSES[po.status]}`}>{PO_STATUS_LABELS[po.status]}</span>
                </h1>
                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        {po.status === 'draft' && (
                            <Link to="/purchase-orders/$poId/edit" params={{ poId: String(po.id) }} className="rounded-md border px-3 py-1.5 text-sm">
                                Edit
                            </Link>
                        )}
                        {nextStatuses.includes('ordered') && (
                            <button type="button" onClick={() => void go('ordered')} disabled={transition.isPending} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                                Mark as ordered
                            </button>
                        )}
                        {canReceive && (
                            <button type="button" onClick={() => setReceiveOpen(true)} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                                Receive goods
                            </button>
                        )}
                        {nextStatuses.includes('cancelled') && (
                            <button type="button" onClick={() => setCancelOpen(true)} disabled={transition.isPending} className="rounded-md border px-3 py-1.5 text-sm text-red-700 disabled:opacity-50">
                                Cancel PO
                            </button>
                        )}
                    </div>
                )}
            </div>

            {actionError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {actionError}
                </p>
            )}

            <dl className="mb-6 grid max-w-3xl grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Supplier</dt>
                    <dd className="font-medium">{po.supplier?.name ?? `#${po.supplier_id}`}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Expected</dt>
                    <dd>{po.expected_date ?? '-'}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Ordered</dt>
                    <dd>{po.ordered_at ? new Date(po.ordered_at).toLocaleString() : '-'}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Received</dt>
                    <dd>{po.received_at ? new Date(po.received_at).toLocaleString() : '-'}</dd>
                </div>
                {po.notes && (
                    <div className="col-span-2 sm:col-span-4">
                        <dt className="text-[var(--color-ink-muted)]">Notes</dt>
                        <dd>{po.notes}</dd>
                    </div>
                )}
                {po.cancel_reason && (
                    <div className="col-span-2 sm:col-span-4">
                        <dt className="text-[var(--color-ink-muted)]">Cancelled because</dt>
                        <dd>{po.cancel_reason}</dd>
                    </div>
                )}
            </dl>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th className="border-b px-3 py-2 text-left font-medium">Item</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Ordered</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Received</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Remaining</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Unit cost</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l) => (
                            <tr key={l.id}>
                                <td className="border-b px-3 py-2 align-top">
                                    <Link to="/inventory/$itemId" params={{ itemId: String(l.inventory_item_id) }} className="font-mono text-xs underline">
                                        {l.sku ?? '?'}
                                    </Link>
                                    <div className="text-xs text-[var(--color-ink-muted)]">{l.name}</div>
                                </td>
                                <td className="border-b px-3 py-2 text-right align-top">
                                    {formatQty(l.quantity)} {l.unit}
                                </td>
                                <td className="border-b px-3 py-2 text-right align-top">{formatQty(l.received_quantity)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{formatQty(l.remaining)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{money(l.unit_cost)}</td>
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

            <dl className="mt-3 ml-auto grid w-64 grid-cols-2 gap-y-1 text-sm">
                <dt className="font-medium">Total</dt>
                <dd className="text-right font-medium">{money(po.subtotal)}</dd>
            </dl>

            <CancelModal open={cancelOpen} onClose={() => setCancelOpen(false)} onConfirm={(reason) => go('cancelled', reason)} />
            <ReceiveModal open={receiveOpen} onClose={() => setReceiveOpen(false)} po={po} />
        </div>
    );
}
