import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { purchasingErrorMessage, purchasingFieldErrors, useSaveSupplier } from '../api/usePurchasingMutations';
import { supplierFormSchema, type Supplier, type SupplierFormValues } from '../schemas/purchasing';

const FIELDS = ['name', 'email', 'phone', 'notes'] as const;

function blank(s?: Supplier | null): SupplierFormValues {
    return s
        ? { name: s.name, email: s.email ?? '', phone: s.phone ?? '', notes: s.notes ?? '', is_active: s.is_active }
        : { name: '', email: '', phone: '', notes: '', is_active: true };
}

// 11.2c: create / edit a supplier.
export function SupplierFormModal({ open, onClose, supplier }: { open: boolean; onClose: () => void; supplier?: Supplier | null }) {
    const save = useSaveSupplier();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<SupplierFormValues>({ resolver: zodResolver(supplierFormSchema), defaultValues: blank(supplier) });
    const editing = !!supplier;

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset(blank(supplier));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, supplier?.id]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await save.mutateAsync({ supplierId: supplier?.id, values });
            onClose();
        } catch (err) {
            const fields = purchasingFieldErrors(err);
            let shown = false;
            for (const f of FIELDS) {
                if (fields[f]) {
                    form.setError(f, { message: fields[f] });
                    shown = true;
                }
            }
            if (!shown) setFormError(purchasingErrorMessage(err, 'Could not save the supplier. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';
    const errors = form.formState.errors;

    return (
        <Modal open={open} onClose={onClose} title={editing ? 'Edit supplier' : 'New supplier'}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <div>
                    <label htmlFor="sup-name" className="mb-1 block text-sm font-medium">
                        Name
                    </label>
                    <input id="sup-name" aria-invalid={errors.name ? true : undefined} className={inputClass} {...form.register('name')} />
                    {errors.name?.message && (
                        <p role="alert" className={errClass}>
                            {errors.name.message}
                        </p>
                    )}
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="sup-email" className="mb-1 block text-sm font-medium">
                            Email
                        </label>
                        <input id="sup-email" type="email" aria-invalid={errors.email ? true : undefined} className={inputClass} {...form.register('email')} />
                        {errors.email?.message && (
                            <p role="alert" className={errClass}>
                                {errors.email.message}
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="sup-phone" className="mb-1 block text-sm font-medium">
                            Phone
                        </label>
                        <input id="sup-phone" aria-invalid={errors.phone ? true : undefined} className={inputClass} {...form.register('phone')} />
                        {errors.phone?.message && (
                            <p role="alert" className={errClass}>
                                {errors.phone.message}
                            </p>
                        )}
                    </div>
                </div>
                <div>
                    <label htmlFor="sup-notes" className="mb-1 block text-sm font-medium">
                        Notes
                    </label>
                    <textarea id="sup-notes" rows={3} className={inputClass} {...form.register('notes')} />
                    {errors.notes?.message && (
                        <p role="alert" className={errClass}>
                            {errors.notes.message}
                        </p>
                    )}
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" {...form.register('is_active')} />
                    Active (inactive suppliers cannot take new purchase orders)
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
                        {form.formState.isSubmitting ? 'Saving...' : editing ? 'Save changes' : 'Create supplier'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
