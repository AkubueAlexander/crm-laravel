import { useEffect, useRef, useState } from 'react';
import { useFieldArray, useForm, useWatch, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { useInventoryItems } from '@/features/inventory/api/useInventory';
import { useSupplierOptions, usePurchaseOrder } from '../api/usePurchasing';
import { isStale, purchasingFieldErrors, useSavePurchaseOrder } from '../api/usePurchasingMutations';
import { emptyPoLine, estimateLineTotal, poFormSchema, type PoFormValues, type PurchaseOrder } from '../schemas/purchasing';

const HEADER_FIELDS = ['supplier_id', 'currency', 'expected_date', 'notes'] as const;
const LINE_FIELD_RE = /^lines\.(\d+)\.(inventory_item_id|quantity|unit_cost)$/;

function blankPo(): PoFormValues {
    return { supplier_id: '', currency: 'USD', expected_date: '', notes: '', lines: [emptyPoLine()] };
}

function toFormValues(po: PurchaseOrder): PoFormValues {
    const lines = (po.lines ?? []).map((l) => ({ inventory_item_id: String(l.inventory_item_id), quantity: l.quantity, unit_cost: l.unit_cost }));
    return {
        supplier_id: String(po.supplier_id),
        currency: po.currency,
        expected_date: po.expected_date ?? '',
        notes: po.notes ?? '',
        lines: lines.length > 0 ? lines : [emptyPoLine()],
    };
}

// 11.2c: purchase order builder (create, or edit a draft). useFieldArray line items; the total shown is an estimate,
// the server computes the real one. Editing sends the lock_version (409 when someone else changed the PO).
export function PurchaseOrderBuilder() {
    const canManage = usePermission('inventory.manage');
    const navigate = useNavigate();
    const params = useParams({ strict: false }) as { poId?: string };
    const editId = params.poId !== undefined ? Number(params.poId) : null;
    const validEdit = editId !== null && Number.isInteger(editId) && editId > 0;
    const editing = params.poId !== undefined;

    const poQuery = usePurchaseOrder(validEdit ? editId : null);
    const suppliers = useSupplierOptions(canManage);
    const items = useInventoryItems({ perPage: 100, active: 'active', sort: 'sku', enabled: canManage });
    const save = useSavePurchaseOrder();
    const [formError, setFormError] = useState<string | null>(null);

    const form = useForm<PoFormValues>({ resolver: zodResolver(poFormSchema), defaultValues: blankPo() });
    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'lines' });
    const watchedLines = useWatch({ control: form.control, name: 'lines' });
    const currency = useWatch({ control: form.control, name: 'currency' });

    const resetFor = useRef<number | null>(null);
    const po = poQuery.data;
    useEffect(() => {
        if (po && resetFor.current !== po.id) {
            resetFor.current = po.id;
            form.reset(toFormValues(po));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [po]);

    if (!canManage) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to edit purchase orders.</p>;
    if (editing && !validEdit) return <p className="text-sm text-red-700">Invalid purchase order.</p>;
    if (editing && poQuery.isPending) return <p className="text-sm text-gray-500">Loading...</p>;
    if (editing && (poQuery.isError || !po)) {
        return (
            <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {apiErrorMessage(poQuery.error, "Couldn't load this purchase order.")}
            </p>
        );
    }
    if (po && po.status !== 'draft') {
        return (
            <p className="text-sm text-[var(--color-ink-muted)]">
                Only draft purchase orders can be edited.{' '}
                <Link to="/purchase-orders/$poId" params={{ poId: String(po.id) }} className="underline">
                    Back to {po.po_number}
                </Link>
            </p>
        );
    }

    // Pickers: active suppliers/items, plus whatever the PO being edited already points at.
    const supplierOptions = [...(suppliers.data ?? []).map((s) => ({ id: String(s.id), name: s.name }))];
    if (po?.supplier && !supplierOptions.some((s) => s.id === String(po.supplier?.id))) supplierOptions.push({ id: String(po.supplier.id), name: po.supplier.name });

    const itemOptions = (items.data?.data ?? []).map((i) => ({ id: String(i.id), label: `${i.sku} - ${i.name}`, unit: i.unit }));
    for (const l of po?.lines ?? []) {
        if (!itemOptions.some((o) => o.id === String(l.inventory_item_id))) {
            itemOptions.push({ id: String(l.inventory_item_id), label: `${l.sku ?? '?'} - ${l.name ?? ''}`, unit: l.unit ?? '' });
        }
    }
    const unitOf = (id: string) => itemOptions.find((o) => o.id === id)?.unit ?? '';

    const lineTotals = (watchedLines ?? []).map((l) => estimateLineTotal(l?.quantity ?? '', l?.unit_cost ?? ''));
    const subtotal = lineTotals.reduce<number>((sum, t) => sum + (t ?? 0), 0);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            const saved = await save.mutateAsync({ poId: po?.id, lockVersion: po?.lock_version, values });
            void navigate({ to: '/purchase-orders/$poId', params: { poId: String(saved.id) } });
        } catch (err) {
            if (isStale(err)) {
                setFormError('This purchase order was updated by someone else. Open it to see the latest version, then edit again.');
                return;
            }
            const fields422 = purchasingFieldErrors(err);
            let shown = false;
            for (const [key, message] of Object.entries(fields422)) {
                if ((HEADER_FIELDS as readonly string[]).includes(key) || LINE_FIELD_RE.test(key)) {
                    form.setError(key as Path<PoFormValues>, { message });
                    shown = true;
                }
            }
            if (!shown) setFormError(fields422.lines ?? fields422.po ?? fields422.lock_version ?? apiErrorMessage(err, 'Could not save the purchase order. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';
    const errors = form.formState.errors;
    const money = (n: number) => formatMoney(n.toFixed(2), currency || 'USD');

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/purchase-orders" className="text-[var(--color-ink-muted)] underline">
                    Purchase orders
                </Link>
            </div>
            <h1 className="mb-4 text-lg font-medium text-[var(--color-ink)]">{po ? `Edit ${po.po_number}` : 'New purchase order'}</h1>

            <form onSubmit={onSubmit} noValidate className="flex max-w-4xl flex-col gap-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div className="sm:col-span-2">
                        <label htmlFor="po-supplier" className="mb-1 block text-sm font-medium">
                            Supplier
                        </label>
                        <select id="po-supplier" aria-invalid={errors.supplier_id ? true : undefined} className={inputClass} {...form.register('supplier_id')}>
                            <option value="">Choose a supplier...</option>
                            {supplierOptions.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                        {errors.supplier_id?.message && (
                            <p role="alert" className={errClass}>
                                {errors.supplier_id.message}
                            </p>
                        )}
                        {suppliers.isSuccess && supplierOptions.length === 0 && (
                            <p className="mt-1 text-xs text-[var(--color-ink-muted)]">
                                No active suppliers yet.{' '}
                                <Link to="/suppliers" className="underline">
                                    Add one
                                </Link>
                                .
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="po-currency" className="mb-1 block text-sm font-medium">
                            Currency
                        </label>
                        <input id="po-currency" maxLength={3} aria-invalid={errors.currency ? true : undefined} className={inputClass} {...form.register('currency')} />
                        {errors.currency?.message && (
                            <p role="alert" className={errClass}>
                                {errors.currency.message}
                            </p>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label htmlFor="po-expected" className="mb-1 block text-sm font-medium">
                            Expected date
                        </label>
                        <input id="po-expected" type="date" aria-invalid={errors.expected_date ? true : undefined} className={inputClass} {...form.register('expected_date')} />
                        {errors.expected_date?.message && (
                            <p role="alert" className={errClass}>
                                {errors.expected_date.message}
                            </p>
                        )}
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="po-notes" className="mb-1 block text-sm font-medium">
                            Notes
                        </label>
                        <input id="po-notes" aria-invalid={errors.notes ? true : undefined} className={inputClass} {...form.register('notes')} />
                        {errors.notes?.message && (
                            <p role="alert" className={errClass}>
                                {errors.notes.message}
                            </p>
                        )}
                    </div>
                </div>

                <section>
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-base font-medium text-[var(--color-ink)]">Lines</h2>
                        <button type="button" onClick={() => append(emptyPoLine())} className="rounded-md border px-3 py-1.5 text-sm">
                            Add line
                        </button>
                    </div>
                    {items.isSuccess && itemOptions.length === 0 && (
                        <p className="mb-2 text-xs text-[var(--color-ink-muted)]">
                            No active inventory items yet.{' '}
                            <Link to="/inventory" className="underline">
                                Add one
                            </Link>
                            .
                        </p>
                    )}
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr>
                                    <th className="border-b px-2 py-2 text-left font-medium">Item</th>
                                    <th className="border-b px-2 py-2 text-left font-medium">Quantity</th>
                                    <th className="border-b px-2 py-2 text-left font-medium">Unit cost</th>
                                    <th className="border-b px-2 py-2 text-right font-medium">Line total</th>
                                    <th className="border-b px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {fields.map((field, index) => {
                                    const rowErrors = errors.lines?.[index];
                                    const itemId = watchedLines?.[index]?.inventory_item_id ?? '';
                                    const total = lineTotals[index];
                                    return (
                                        <tr key={field.id}>
                                            <td className="border-b px-2 py-2 align-top">
                                                <select aria-label={`Item for line ${index + 1}`} aria-invalid={rowErrors?.inventory_item_id ? true : undefined} className={inputClass} {...form.register(`lines.${index}.inventory_item_id`)}>
                                                    <option value="">Choose an item...</option>
                                                    {itemOptions.map((o) => (
                                                        <option key={o.id} value={o.id}>
                                                            {o.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {rowErrors?.inventory_item_id?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.inventory_item_id.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 align-top">
                                                <div className="flex items-center gap-1">
                                                    <input aria-label={`Quantity for line ${index + 1}`} inputMode="decimal" aria-invalid={rowErrors?.quantity ? true : undefined} className="w-24 rounded-md border px-3 py-2 text-sm" {...form.register(`lines.${index}.quantity`)} />
                                                    <span className="text-xs text-[var(--color-ink-muted)]">{unitOf(itemId)}</span>
                                                </div>
                                                {rowErrors?.quantity?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.quantity.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 align-top">
                                                <input aria-label={`Unit cost for line ${index + 1}`} inputMode="decimal" aria-invalid={rowErrors?.unit_cost ? true : undefined} className="w-28 rounded-md border px-3 py-2 text-sm" {...form.register(`lines.${index}.unit_cost`)} />
                                                {rowErrors?.unit_cost?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.unit_cost.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 text-right align-top">{total === null || total === undefined ? '-' : money(total)}</td>
                                            <td className="border-b px-2 py-2 text-right align-top">
                                                <button type="button" onClick={() => remove(index)} disabled={fields.length <= 1} className="rounded-md border px-2 py-1 text-xs text-red-700 disabled:opacity-40">
                                                    Remove
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <dl className="mt-3 ml-auto grid w-64 grid-cols-2 gap-y-1 text-sm">
                        <dt className="font-medium">Estimated total</dt>
                        <dd className="text-right font-medium">{money(subtotal)}</dd>
                    </dl>
                    <p className="text-right text-xs text-[var(--color-ink-muted)]">The server computes the final total when you save.</p>
                </section>

                {formError && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {formError}
                    </p>
                )}

                <div className="flex justify-end gap-2">
                    <Link to={po ? '/purchase-orders/$poId' : '/purchase-orders'} params={po ? { poId: String(po.id) } : undefined} className="rounded-md border px-3 py-1.5 text-sm">
                        Cancel
                    </Link>
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Saving...' : po ? 'Save changes' : 'Create purchase order'}
                    </button>
                </div>
            </form>
        </div>
    );
}
