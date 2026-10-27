import { useEffect, useState } from 'react';
import { useFieldArray, useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isAxiosError } from 'axios';
import { Modal } from '@/shared/components/Modal';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useProduct } from '../api/useProducts';
import { useSaveProduct } from '../api/useProductMutations';
import { TAX_CATEGORIES, type Product } from '../schemas/product';
import { productFormSchema, toProductInput, type ProductFormValues } from '../schemas/productForm';

type Props = {
    // null = closed, 'new' = create, Product = edit (its variants are loaded fresh).
    target: Product | 'new' | null;
    onClose: () => void;
};

// First message per field from a 422 body shaped { message, errors: { field: [msg, ...] }, code }.
function serverFieldErrors(error: unknown): Record<string, string> {
    if (!isAxiosError(error) || error.response?.status !== 422) return {};
    const errors = (error.response.data as { errors?: Record<string, unknown> } | undefined)?.errors;
    if (!errors || typeof errors !== 'object') return {};
    const out: Record<string, string> = {};
    for (const [field, messages] of Object.entries(errors)) {
        const first = Array.isArray(messages) ? messages[0] : messages;
        if (typeof first === 'string') out[field] = first;
    }
    return out;
}

const FORM_FIELD = /^(sku|name|description|unit_price|tax_category|variants\.\d+\.(sku|name|unit_price))$/;
const INPUT = 'w-full rounded-md border px-3 py-2 text-sm';

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return (
        <p role="alert" className="mt-1 text-xs text-red-700">
            {message}
        </p>
    );
}

function ProductForm({ product, onClose }: { product: Product | null; onClose: () => void }) {
    const save = useSaveProduct();
    const [formError, setFormError] = useState<string | null>(null);

    const {
        register,
        control,
        handleSubmit,
        setError,
        setFocus,
        formState: { errors, isSubmitting },
    } = useForm<ProductFormValues>({
        resolver: zodResolver(productFormSchema),
        defaultValues: {
            sku: product?.sku ?? '',
            name: product?.name ?? '',
            description: product?.description ?? '',
            unit_price: product?.unit_price ?? '',
            tax_category: product?.tax_category ?? 'standard',
            is_active: product?.is_active ?? true,
            variants: (product?.variants ?? []).map((v) => ({
                variant_id: v.id,
                sku: v.sku,
                name: v.name,
                unit_price: v.unit_price ?? '',
                is_active: v.is_active,
            })),
        },
    });

    // 10.0c: each variant row is uncontrolled, so typing in one row does not re-render the others.
    const { fields, append, remove } = useFieldArray({ control, name: 'variants' });

    useEffect(() => {
        setFocus('sku');
    }, [setFocus]);

    const onSubmit = handleSubmit(async (values) => {
        setFormError(null);
        try {
            await save.mutateAsync({ id: product?.id ?? null, input: toProductInput(values) });
            onClose();
        } catch (error) {
            const fields422 = serverFieldErrors(error);
            let handled = false;
            for (const [field, message] of Object.entries(fields422)) {
                if (FORM_FIELD.test(field)) {
                    setError(field as FieldPath<ProductFormValues>, { type: 'server', message });
                    handled = true;
                }
            }
            if (!handled) {
                const other = Object.values(fields422)[0];
                setFormError(other ?? apiErrorMessage(error, 'Could not save the product. Please try again.'));
            }
        }
    });

    const variantsError = typeof errors.variants?.message === 'string' ? errors.variants.message : undefined;

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <label htmlFor="product-sku" className="mb-1 block text-sm font-medium">
                        SKU
                    </label>
                    <input id="product-sku" type="text" autoComplete="off" aria-invalid={errors.sku ? true : undefined} className={INPUT} {...register('sku')} />
                    <FieldError message={errors.sku?.message} />
                </div>
                <div>
                    <label htmlFor="product-price" className="mb-1 block text-sm font-medium">
                        Unit price
                    </label>
                    <input
                        id="product-price"
                        type="text"
                        inputMode="decimal"
                        autoComplete="off"
                        aria-invalid={errors.unit_price ? true : undefined}
                        className={INPUT}
                        {...register('unit_price')}
                    />
                    <FieldError message={errors.unit_price?.message} />
                </div>
            </div>

            <div>
                <label htmlFor="product-name" className="mb-1 block text-sm font-medium">
                    Name
                </label>
                <input id="product-name" type="text" autoComplete="off" aria-invalid={errors.name ? true : undefined} className={INPUT} {...register('name')} />
                <FieldError message={errors.name?.message} />
            </div>

            <div>
                <label htmlFor="product-description" className="mb-1 block text-sm font-medium">
                    Description <span className="font-normal text-[var(--color-ink-muted)]">(optional)</span>
                </label>
                <textarea id="product-description" rows={3} className={INPUT} {...register('description')} />
                <FieldError message={errors.description?.message} />
            </div>

            <div className="grid grid-cols-2 items-end gap-3">
                <div>
                    <label htmlFor="product-tax" className="mb-1 block text-sm font-medium">
                        Tax category
                    </label>
                    <select id="product-tax" className={INPUT} {...register('tax_category')}>
                        {TAX_CATEGORIES.map((c) => (
                            <option key={c} value={c}>
                                {c.charAt(0).toUpperCase() + c.slice(1)}
                            </option>
                        ))}
                    </select>
                </div>
                <label className="flex items-center gap-2 pb-2 text-sm">
                    <input type="checkbox" {...register('is_active')} />
                    Active
                </label>
            </div>

            <fieldset className="rounded-md border border-[var(--color-border)] p-3">
                <legend className="px-1 text-sm font-medium">Variants</legend>

                {fields.length === 0 && <p className="text-xs text-[var(--color-ink-muted)]">No variants. The product itself is what gets sold.</p>}

                <div className="flex flex-col gap-3">
                    {fields.map((field, i) => (
                        <div key={field.id} className="rounded-md border border-[var(--color-border)] p-2">
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <input
                                        type="text"
                                        aria-label={`Variant ${i + 1} SKU`}
                                        placeholder="SKU"
                                        autoComplete="off"
                                        aria-invalid={errors.variants?.[i]?.sku ? true : undefined}
                                        className={INPUT}
                                        {...register(`variants.${i}.sku` as const)}
                                    />
                                    <FieldError message={errors.variants?.[i]?.sku?.message} />
                                </div>
                                <div>
                                    <input
                                        type="text"
                                        aria-label={`Variant ${i + 1} name`}
                                        placeholder="Name"
                                        autoComplete="off"
                                        aria-invalid={errors.variants?.[i]?.name ? true : undefined}
                                        className={INPUT}
                                        {...register(`variants.${i}.name` as const)}
                                    />
                                    <FieldError message={errors.variants?.[i]?.name?.message} />
                                </div>
                                <div>
                                    <input
                                        type="text"
                                        inputMode="decimal"
                                        aria-label={`Variant ${i + 1} price`}
                                        placeholder="Price (blank = product price)"
                                        autoComplete="off"
                                        aria-invalid={errors.variants?.[i]?.unit_price ? true : undefined}
                                        className={INPUT}
                                        {...register(`variants.${i}.unit_price` as const)}
                                    />
                                    <FieldError message={errors.variants?.[i]?.unit_price?.message} />
                                </div>
                                <div className="flex items-center justify-between">
                                    <label className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" {...register(`variants.${i}.is_active` as const)} />
                                        Active
                                    </label>
                                    <button type="button" onClick={() => remove(i)} className="rounded-md border px-2 py-1 text-xs text-red-700">
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                <FieldError message={variantsError} />

                <button
                    type="button"
                    onClick={() => append({ variant_id: null, sku: '', name: '', unit_price: '', is_active: true })}
                    disabled={fields.length >= 50}
                    className="mt-3 rounded-md border px-2 py-1 text-xs disabled:opacity-40"
                >
                    Add variant
                </button>
            </fieldset>

            {formError && (
                <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {formError}
                </p>
            )}

            <div className="flex justify-end gap-2">
                <button type="button" onClick={onClose} disabled={isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                    Cancel
                </button>
                <button
                    type="submit"
                    disabled={isSubmitting}
                    className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                >
                    {isSubmitting ? 'Saving...' : product ? 'Save changes' : 'Create product'}
                </button>
            </div>
        </form>
    );
}

function EditProduct({ summary, onClose }: { summary: Product; onClose: () => void }) {
    const detail = useProduct(summary.id);

    return (
        <Modal open onClose={onClose} title="Edit product">
            {detail.isPending ? (
                <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>
            ) : detail.isError ? (
                <div className="flex flex-col gap-3">
                    <p role="alert" className="text-sm text-red-700">
                        {apiErrorMessage(detail.error, 'Could not load the product.')}
                    </p>
                    <button type="button" onClick={onClose} className="self-end rounded-md border px-3 py-1.5 text-sm">
                        Close
                    </button>
                </div>
            ) : (
                <ProductForm key={detail.data.id} product={detail.data} onClose={onClose} />
            )}
        </Modal>
    );
}

export function ProductFormDialog({ target, onClose }: Props) {
    if (target === null) return null;

    if (target === 'new') {
        return (
            <Modal open onClose={onClose} title="New product">
                <ProductForm key="new" product={null} onClose={onClose} />
            </Modal>
        );
    }

    return <EditProduct key={target.id} summary={target} onClose={onClose} />;
}
