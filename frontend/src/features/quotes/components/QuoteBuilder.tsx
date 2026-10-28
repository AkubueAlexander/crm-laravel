import { useState } from 'react';
import { useFieldArray, useForm, useWatch, type Control, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isAxiosError } from 'axios';
import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useProduct, useProducts } from '@/features/products/api/useProducts';
import { TAX_CATEGORIES, type Product, type ProductVariant } from '@/features/products/schemas/product';
import { useQuote } from '../api/useQuotes';
import { useSaveQuote } from '../api/useQuoteMutations';
import { QUOTE_STATUS_LABELS, formatMoney, type Quote } from '../schemas/quote';
import {
    emptyQuoteValues,
    formatCents,
    previewTotals,
    quoteFormSchema,
    toQuoteInput,
    valuesFromQuote,
    type QuoteFormValues,
    type QuoteLineFormValues,
} from '../schemas/quoteForm';

const INPUT = 'w-full rounded-md border px-3 py-2 text-sm';
const CELL = 'w-full rounded-md border px-2 py-1.5 text-sm';

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

const FORM_FIELD = /^(title|currency|valid_until|notes|terms|lines\.\d+\.(name|description|quantity|unit_price|discount_percent|tax_category))$/;

function capitalize(s: string): string {
    return s.charAt(0).toUpperCase() + s.slice(1);
}

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return (
        <p role="alert" className="mt-1 text-xs text-red-700">
            {message}
        </p>
    );
}

function lineFromCatalog(product: Product, variant: ProductVariant | null): QuoteLineFormValues {
    return {
        product_id: product.id,
        variant_id: variant?.id ?? null,
        sku: variant?.sku ?? product.sku,
        name: variant ? `${product.name} - ${variant.name}` : product.name,
        description: '',
        quantity: '1',
        unit_price: variant?.unit_price ?? product.unit_price,
        discount_percent: '',
        tax_category: product.tax_category,
    };
}

// Reuses the catalog hooks (10.0c). Products with variants open a second step to choose the variant.
function ProductPicker({ onPick }: { onPick: (line: QuoteLineFormValues) => void }) {
    const [qInput, setQInput] = useState('');
    const q = useDebouncedValue(qInput, 300).trim();
    const [selectedId, setSelectedId] = useState<number | null>(null);

    const list = useProducts({ q, status: 'active', perPage: 8, enabled: q.length > 0 });
    const detail = useProduct(selectedId);

    function pick(line: QuoteLineFormValues) {
        onPick(line);
        setQInput('');
        setSelectedId(null);
    }

    return (
        <div className="rounded-md border border-[var(--color-border)] p-3">
            <input
                type="search"
                aria-label="Search the catalog"
                placeholder="Search the catalog by SKU or name..."
                value={qInput}
                onChange={(e) => {
                    setQInput(e.target.value);
                    setSelectedId(null);
                }}
                className={INPUT}
            />

            {q.length > 0 && selectedId === null && (
                <ul className="mt-2 flex flex-col gap-1">
                    {list.isPending && <li className="text-xs text-[var(--color-ink-muted)]">Searching...</li>}
                    {list.data?.data.length === 0 && <li className="text-xs text-[var(--color-ink-muted)]">No active products match.</li>}
                    {list.data?.data.map((p) => (
                        <li key={p.id}>
                            <button
                                type="button"
                                onClick={() => ((p.variants_count ?? 0) > 0 ? setSelectedId(p.id) : pick(lineFromCatalog(p, null)))}
                                className="flex w-full items-center justify-between rounded-md border px-2 py-1.5 text-left text-sm hover:bg-[var(--color-paper)]"
                            >
                                <span>
                                    <span className="font-mono text-xs">{p.sku}</span> {p.name}
                                </span>
                                <span className="text-xs text-[var(--color-ink-muted)]">
                                    {(p.variants_count ?? 0) > 0 ? `${p.variants_count} variants` : formatMoney(p.unit_price, 'USD').replace(/^[^\d-]*/, '')}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {selectedId !== null && (
                <div className="mt-2">
                    {detail.isPending && <p className="text-xs text-[var(--color-ink-muted)]">Loading variants...</p>}
                    {detail.isError && (
                        <p role="alert" className="text-xs text-red-700">
                            {apiErrorMessage(detail.error, 'Could not load the product.')}
                        </p>
                    )}
                    {detail.data && (
                        <div className="flex flex-col gap-1">
                            <p className="text-xs text-[var(--color-ink-muted)]">Choose what to add for {detail.data.name}:</p>
                            <button
                                type="button"
                                onClick={() => pick(lineFromCatalog(detail.data, null))}
                                className="rounded-md border px-2 py-1.5 text-left text-sm hover:bg-[var(--color-paper)]"
                            >
                                {detail.data.name} (base product)
                            </button>
                            {(detail.data.variants ?? [])
                                .filter((v) => v.is_active)
                                .map((v) => (
                                    <button
                                        key={v.id}
                                        type="button"
                                        onClick={() => pick(lineFromCatalog(detail.data, v))}
                                        className="rounded-md border px-2 py-1.5 text-left text-sm hover:bg-[var(--color-paper)]"
                                    >
                                        <span className="font-mono text-xs">{v.sku}</span> {v.name}
                                    </button>
                                ))}
                            <button type="button" onClick={() => setSelectedId(null)} className="self-start text-xs text-[var(--color-ink-muted)] underline">
                                Back to results
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// Display-only estimate with the same rounding as QuoteTotals. Own component so only it re-renders on typing.
function TotalsPreview({ control }: { control: Control<QuoteFormValues> }) {
    const lines = useWatch({ control, name: 'lines' });
    const currency = useWatch({ control, name: 'currency' }) ?? 'USD';
    const t = previewTotals(lines ?? []);

    return (
        <dl className="ml-auto grid w-64 grid-cols-2 gap-y-1 text-sm">
            <dt className="text-[var(--color-ink-muted)]">Subtotal</dt>
            <dd className="text-right">{formatCents(t.subtotalCents, currency)}</dd>
            <dt className="text-[var(--color-ink-muted)]">Tax (estimate)</dt>
            <dd className="text-right">{formatCents(t.taxCents, currency)}</dd>
            <dt className="font-medium">Total (estimate)</dt>
            <dd className="text-right font-medium">{formatCents(t.totalCents, currency)}</dd>
        </dl>
    );
}

function QuoteEditor({ quote }: { quote: Quote | null }) {
    const canManage = usePermission('quotes.manage');
    const readOnly = !canManage || (quote !== null && quote.status !== 'draft');
    const save = useSaveQuote();
    const navigate = useNavigate();
    const [formError, setFormError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [saved, setSaved] = useState<Quote | null>(quote);

    const {
        register,
        control,
        handleSubmit,
        setError,
        reset,
        formState: { errors, isSubmitting },
    } = useForm<QuoteFormValues>({
        resolver: zodResolver(quoteFormSchema),
        defaultValues: quote ? valuesFromQuote(quote) : emptyQuoteValues(),
    });

    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

    const onSubmit = handleSubmit(async (values) => {
        setFormError(null);
        setNotice(null);
        try {
            const result = await save.mutateAsync({ id: quote?.id ?? null, input: toQuoteInput(values) });
            if (quote === null) {
                await navigate({ to: '/quotes/$quoteId', params: { quoteId: String(result.id) }, replace: true });
                return;
            }
            reset(valuesFromQuote(result));
            setSaved(result);
            setNotice('Saved.');
        } catch (error) {
            const fields422 = serverFieldErrors(error);
            let handled = false;
            for (const [field, message] of Object.entries(fields422)) {
                if (FORM_FIELD.test(field)) {
                    setError(field as FieldPath<QuoteFormValues>, { type: 'server', message });
                    handled = true;
                }
            }
            if (!handled) {
                const other = Object.values(fields422)[0];
                setFormError(other ?? apiErrorMessage(error, 'Could not save the quote. Please try again.'));
            }
        }
    });

    const linesError = typeof errors.lines?.message === 'string' ? errors.lines.message : undefined;

    return (
        <div className="max-w-5xl">
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <Link to="/quotes" search={{}} className="text-sm text-[var(--color-ink-muted)] underline">
                        Quotes
                    </Link>
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">
                        {quote ? `${quote.quote_number}${quote.version > 1 ? ` v${quote.version}` : ''}` : 'New quote'}
                    </h1>
                    {quote && (
                        <span className="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                            {QUOTE_STATUS_LABELS[quote.status]}
                        </span>
                    )}
                </div>
            </div>

            {readOnly && quote && (
                <p className="mb-3 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-ink-muted)]">
                    {quote.status !== 'draft' ? 'Only draft quotes can be edited.' : 'You can view this quote but not change it.'}
                </p>
            )}

            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
                <fieldset disabled={readOnly} className="flex flex-col gap-4">
                    <div className="grid grid-cols-6 gap-3">
                        <div className="col-span-6 md:col-span-3">
                            <label htmlFor="quote-title" className="mb-1 block text-sm font-medium">
                                Title
                            </label>
                            <input id="quote-title" type="text" autoComplete="off" aria-invalid={errors.title ? true : undefined} className={INPUT} {...register('title')} />
                            <FieldError message={errors.title?.message} />
                        </div>
                        <div className="col-span-3 md:col-span-1">
                            <label htmlFor="quote-currency" className="mb-1 block text-sm font-medium">
                                Currency
                            </label>
                            <input id="quote-currency" type="text" maxLength={3} autoComplete="off" className={INPUT} {...register('currency')} />
                            <FieldError message={errors.currency?.message} />
                        </div>
                        <div className="col-span-3 md:col-span-2">
                            <label htmlFor="quote-valid" className="mb-1 block text-sm font-medium">
                                Valid until
                            </label>
                            <input id="quote-valid" type="date" className={INPUT} {...register('valid_until')} />
                            <FieldError message={errors.valid_until?.message} />
                        </div>
                    </div>

                    <fieldset className="rounded-md border border-[var(--color-border)] p-3">
                        <legend className="px-1 text-sm font-medium">Lines</legend>

                        {!readOnly && (
                            <div className="mb-3">
                                <ProductPicker onPick={(line) => append(line)} />
                            </div>
                        )}

                        {fields.length === 0 && <p className="text-xs text-[var(--color-ink-muted)]">No lines yet. Add a catalog item or a custom line.</p>}

                        <div className="flex flex-col gap-3">
                            {fields.map((field, i) => {
                                const catalog = field.product_id !== null;
                                const lineErrors = errors.lines?.[i];
                                return (
                                    <div key={field.id} className="rounded-md border border-[var(--color-border)] p-2">
                                        <div className="grid grid-cols-12 gap-2">
                                            <div className="col-span-12 md:col-span-4">
                                                {catalog ? (
                                                    <div className="text-sm">
                                                        <div className="font-medium">{field.name}</div>
                                                        <div className="font-mono text-xs text-[var(--color-ink-muted)]">{field.sku}</div>
                                                    </div>
                                                ) : (
                                                    <>
                                                        <input
                                                            type="text"
                                                            aria-label={`Line ${i + 1} name`}
                                                            placeholder="Item name"
                                                            autoComplete="off"
                                                            aria-invalid={lineErrors?.name ? true : undefined}
                                                            className={CELL}
                                                            {...register(`lines.${i}.name` as const)}
                                                        />
                                                        <FieldError message={lineErrors?.name?.message} />
                                                    </>
                                                )}
                                                <input
                                                    type="text"
                                                    aria-label={`Line ${i + 1} description`}
                                                    placeholder="Description (optional)"
                                                    autoComplete="off"
                                                    className={`${CELL} mt-1`}
                                                    {...register(`lines.${i}.description` as const)}
                                                />
                                                <FieldError message={lineErrors?.description?.message} />
                                            </div>
                                            <div className="col-span-4 md:col-span-2">
                                                <input
                                                    type="text"
                                                    inputMode="decimal"
                                                    aria-label={`Line ${i + 1} quantity`}
                                                    placeholder="Qty"
                                                    autoComplete="off"
                                                    aria-invalid={lineErrors?.quantity ? true : undefined}
                                                    className={CELL}
                                                    {...register(`lines.${i}.quantity` as const)}
                                                />
                                                <FieldError message={lineErrors?.quantity?.message} />
                                            </div>
                                            <div className="col-span-4 md:col-span-2">
                                                <input
                                                    type="text"
                                                    inputMode="decimal"
                                                    aria-label={`Line ${i + 1} unit price`}
                                                    placeholder="Unit price"
                                                    autoComplete="off"
                                                    aria-invalid={lineErrors?.unit_price ? true : undefined}
                                                    className={CELL}
                                                    {...register(`lines.${i}.unit_price` as const)}
                                                />
                                                <FieldError message={lineErrors?.unit_price?.message} />
                                            </div>
                                            <div className="col-span-4 md:col-span-1">
                                                <input
                                                    type="text"
                                                    inputMode="decimal"
                                                    aria-label={`Line ${i + 1} discount percent`}
                                                    placeholder="Disc %"
                                                    autoComplete="off"
                                                    aria-invalid={lineErrors?.discount_percent ? true : undefined}
                                                    className={CELL}
                                                    {...register(`lines.${i}.discount_percent` as const)}
                                                />
                                                <FieldError message={lineErrors?.discount_percent?.message} />
                                            </div>
                                            <div className="col-span-8 md:col-span-2">
                                                {catalog ? (
                                                    <span className="text-xs text-[var(--color-ink-muted)]">Tax: {capitalize(field.tax_category)}</span>
                                                ) : (
                                                    <select aria-label={`Line ${i + 1} tax category`} className={CELL} {...register(`lines.${i}.tax_category` as const)}>
                                                        {TAX_CATEGORIES.map((c) => (
                                                            <option key={c} value={c}>
                                                                {capitalize(c)}
                                                            </option>
                                                        ))}
                                                    </select>
                                                )}
                                                <FieldError message={lineErrors?.tax_category?.message} />
                                            </div>
                                            <div className="col-span-4 md:col-span-1">
                                                {!readOnly && (
                                                    <button type="button" onClick={() => remove(i)} className="rounded-md border px-2 py-1 text-xs text-red-700">
                                                        Remove
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        <FieldError message={linesError} />

                        {!readOnly && (
                            <button
                                type="button"
                                onClick={() =>
                                    append({
                                        product_id: null,
                                        variant_id: null,
                                        sku: '',
                                        name: '',
                                        description: '',
                                        quantity: '1',
                                        unit_price: '',
                                        discount_percent: '',
                                        tax_category: 'standard',
                                    })
                                }
                                disabled={fields.length >= 100}
                                className="mt-3 rounded-md border px-2 py-1 text-xs disabled:opacity-40"
                            >
                                Add custom line
                            </button>
                        )}
                    </fieldset>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label htmlFor="quote-notes" className="mb-1 block text-sm font-medium">
                                Notes <span className="font-normal text-[var(--color-ink-muted)]">(optional)</span>
                            </label>
                            <textarea id="quote-notes" rows={3} className={INPUT} {...register('notes')} />
                            <FieldError message={errors.notes?.message} />
                        </div>
                        <div>
                            <label htmlFor="quote-terms" className="mb-1 block text-sm font-medium">
                                Terms <span className="font-normal text-[var(--color-ink-muted)]">(optional)</span>
                            </label>
                            <textarea id="quote-terms" rows={3} className={INPUT} {...register('terms')} />
                            <FieldError message={errors.terms?.message} />
                        </div>
                    </div>
                </fieldset>

                <div className="flex flex-col gap-1">
                    <TotalsPreview control={control} />
                    {saved && (
                        <p className="ml-auto text-xs text-[var(--color-ink-muted)]">
                            Saved total (from the server): {formatMoney(saved.total, saved.currency)} incl. tax {formatMoney(saved.tax_total, saved.currency)}
                        </p>
                    )}
                </div>

                {formError && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {formError}
                    </p>
                )}
                {notice && <p className="text-sm text-green-700">{notice}</p>}

                {!readOnly && (
                    <div className="flex justify-end gap-2">
                        <Link to="/quotes" search={{}} className="rounded-md border px-3 py-1.5 text-sm">
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            disabled={isSubmitting}
                            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                        >
                            {isSubmitting ? 'Saving...' : quote ? 'Save changes' : 'Create quote'}
                        </button>
                    </div>
                )}
            </form>
        </div>
    );
}

export function QuoteBuilder() {
    const viewAllowed = usePermission('quotes.view');
    const canManage = usePermission('quotes.manage');
    const { quoteId } = useParams({ strict: false }) as { quoteId?: string };
    const id = quoteId === undefined ? null : Number(quoteId);
    const validId = id !== null && Number.isInteger(id) && id > 0 ? id : null;
    const detail = useQuote(validId);

    if (!viewAllowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to quotes.</p>;
    }

    if (id === null) {
        if (!canManage) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have permission to create quotes.</p>;
        return <QuoteEditor key="new" quote={null} />;
    }

    if (validId === null) return <p className="text-sm text-[var(--color-ink-muted)]">Quote not found.</p>;

    if (detail.isPending) return <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>;

    if (detail.isError) {
        return (
            <div className="flex flex-col gap-3">
                <p role="alert" className="text-sm text-red-700">
                    {apiErrorMessage(detail.error, 'Could not load the quote.')}
                </p>
                <Link to="/quotes" search={{}} className="text-sm underline">
                    Back to quotes
                </Link>
            </div>
        );
    }

    return <QuoteEditor key={detail.data.id} quote={detail.data} />;
}
