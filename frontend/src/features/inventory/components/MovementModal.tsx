import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { inventoryFieldErrors, useRecordMovement } from '../api/useInventoryMutations';
import { MOVEMENT_TYPES, MOVEMENT_TYPE_LABELS, formatQty, movementFormSchema, type InventoryItem, type MovementFormValues } from '../schemas/inventory';

const FIELDS = ['type', 'quantity', 'reference', 'note'] as const;
const BLANK: MovementFormValues = { type: 'receipt', quantity: '', reference: '', note: '' };

// 11.0c: record a stock movement. Not optimistic: the server computes the balance and refuses an issue larger than stock.
export function MovementModal({ open, onClose, item }: { open: boolean; onClose: () => void; item: InventoryItem }) {
    const record = useRecordMovement();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<MovementFormValues>({ resolver: zodResolver(movementFormSchema), defaultValues: BLANK });
    const type = form.watch('type');

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset(BLANK);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await record.mutateAsync({ itemId: item.id, values });
            onClose();
        } catch (err) {
            const fields = inventoryFieldErrors(err);
            let shown = false;
            for (const f of FIELDS) {
                if (fields[f]) {
                    form.setError(f, { message: fields[f] });
                    shown = true;
                }
            }
            if (!shown) setFormError(fields.item ?? apiErrorMessage(err, 'Could not record the movement. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';
    const errors = form.formState.errors;

    return (
        <Modal open={open} onClose={onClose} title="Record stock movement">
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <p className="text-sm text-[var(--color-ink-muted)]">
                    {item.sku} - {item.name}. On hand: {formatQty(item.on_hand)} {item.unit}.
                </p>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="mv-type" className="mb-1 block text-sm font-medium">
                            Type
                        </label>
                        <select id="mv-type" className={inputClass} {...form.register('type')}>
                            {MOVEMENT_TYPES.map((t) => (
                                <option key={t} value={t}>
                                    {MOVEMENT_TYPE_LABELS[t]}
                                </option>
                            ))}
                        </select>
                        {errors.type?.message && (
                            <p role="alert" className={errClass}>
                                {errors.type.message}
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="mv-quantity" className="mb-1 block text-sm font-medium">
                            Quantity ({item.unit})
                        </label>
                        <input id="mv-quantity" inputMode="decimal" aria-invalid={errors.quantity ? true : undefined} className={inputClass} {...form.register('quantity')} />
                        {errors.quantity?.message && (
                            <p role="alert" className={errClass}>
                                {errors.quantity.message}
                            </p>
                        )}
                    </div>
                </div>
                {type === 'adjustment' && <p className="text-xs text-[var(--color-ink-muted)]">Adjustments may be negative (e.g. -2 for a stock-take loss) and need a reason.</p>}
                <div>
                    <label htmlFor="mv-reference" className="mb-1 block text-sm font-medium">
                        Reference
                    </label>
                    <input id="mv-reference" className={inputClass} {...form.register('reference')} />
                    {errors.reference?.message && (
                        <p role="alert" className={errClass}>
                            {errors.reference.message}
                        </p>
                    )}
                </div>
                <div>
                    <label htmlFor="mv-note" className="mb-1 block text-sm font-medium">
                        {type === 'adjustment' ? 'Reason' : 'Note'}
                    </label>
                    <textarea id="mv-note" rows={3} aria-invalid={errors.note ? true : undefined} className={inputClass} {...form.register('note')} />
                    {errors.note?.message && (
                        <p role="alert" className={errClass}>
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
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Saving...' : 'Record movement'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
