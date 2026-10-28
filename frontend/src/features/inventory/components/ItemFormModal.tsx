import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { inventoryFieldErrors, useSaveInventoryItem } from '../api/useInventoryMutations';
import { itemFormSchema, type InventoryItem, type ItemFormValues } from '../schemas/inventory';

const FIELDS = ['sku', 'name', 'unit', 'reorder_level'] as const;

function blank(item?: InventoryItem | null): ItemFormValues {
    return item
        ? { sku: item.sku, name: item.name, unit: item.unit, reorder_level: item.reorder_level, is_active: item.is_active }
        : { sku: '', name: '', unit: 'pcs', reorder_level: '0', is_active: true };
}

// 11.0c: create / edit an item. Never touches stock (movements do). SKU is read-only on edit.
export function ItemFormModal({ open, onClose, item }: { open: boolean; onClose: () => void; item?: InventoryItem | null }) {
    const save = useSaveInventoryItem();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<ItemFormValues>({ resolver: zodResolver(itemFormSchema), defaultValues: blank(item) });
    const editing = !!item;

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset(blank(item));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, item?.id]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await save.mutateAsync({ itemId: item?.id, values });
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
            if (!shown) setFormError(apiErrorMessage(err, 'Could not save the item. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';
    const errors = form.formState.errors;

    return (
        <Modal open={open} onClose={onClose} title={editing ? 'Edit item' : 'New item'}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="item-sku" className="mb-1 block text-sm font-medium">
                            SKU
                        </label>
                        <input
                            id="item-sku"
                            readOnly={editing}
                            aria-invalid={errors.sku ? true : undefined}
                            className={`${inputClass} ${editing ? 'bg-gray-50 text-[var(--color-ink-muted)]' : ''}`}
                            {...form.register('sku')}
                        />
                        {errors.sku?.message && (
                            <p role="alert" className={errClass}>
                                {errors.sku.message}
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="item-unit" className="mb-1 block text-sm font-medium">
                            Unit
                        </label>
                        <input id="item-unit" aria-invalid={errors.unit ? true : undefined} className={inputClass} {...form.register('unit')} />
                        {errors.unit?.message && (
                            <p role="alert" className={errClass}>
                                {errors.unit.message}
                            </p>
                        )}
                    </div>
                </div>
                <div>
                    <label htmlFor="item-name" className="mb-1 block text-sm font-medium">
                        Name
                    </label>
                    <input id="item-name" aria-invalid={errors.name ? true : undefined} className={inputClass} {...form.register('name')} />
                    {errors.name?.message && (
                        <p role="alert" className={errClass}>
                            {errors.name.message}
                        </p>
                    )}
                </div>
                <div>
                    <label htmlFor="item-reorder" className="mb-1 block text-sm font-medium">
                        Reorder level (0 = no alert)
                    </label>
                    <input id="item-reorder" inputMode="decimal" aria-invalid={errors.reorder_level ? true : undefined} className={inputClass} {...form.register('reorder_level')} />
                    {errors.reorder_level?.message && (
                        <p role="alert" className={errClass}>
                            {errors.reorder_level.message}
                        </p>
                    )}
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" {...form.register('is_active')} />
                    Active (inactive items cannot take stock movements)
                </label>
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
                        {form.formState.isSubmitting ? 'Saving...' : editing ? 'Save changes' : 'Create item'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
