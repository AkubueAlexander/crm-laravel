<?php

namespace App\Domain\Quotes\Actions;

use App\Domain\Quotes\QuoteRules;
use App\Domain\Quotes\QuoteTotals;
use App\Domain\Tenant\TenantContext;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveQuoteAction
{
    public function execute(User $actor, ?int $quoteId, array $data): Quote
    {
        Gate::forUser($actor)->authorize('quotes.manage');

        $tenantId = (int) $actor->tenant_id;

        // Ensure the authenticated user and resolved tenant context agree.
        if (
            $tenantId <= 0 ||
            ! TenantContext::hasTenant() ||
            (int) TenantContext::id() !== $tenantId
        ) {
            throw ValidationException::withMessages([
                'tenant' => ['A valid tenant context is required.'],
            ]);
        }

        // Re-validate here: the Form Request is UX, this is the boundary (2.4).
        $data = Validator::make($data, QuoteRules::all())->validate();

        $lines = array_values($data['lines']);

        $accountId = isset($data['account_id'])
            ? (int) $data['account_id']
            : null;

        $contactId = isset($data['contact_id'])
            ? (int) $data['contact_id']
            : null;

        $this->assertOwned($accountId, $contactId, $tenantId);

        $totals = QuoteTotals::compute(
            $this->resolveLines($lines, $tenantId)
        );

        try {
            return DB::transaction(function () use (
                $actor,
                $quoteId,
                $data,
                $accountId,
                $contactId,
                $totals,
                $tenantId
            ): Quote {
                if ($quoteId === null) {
                    // Lock this tenant's row to serialize quote number allocation.
                    $locked = DB::table('tenants')
                        ->where('id', $tenantId)
                        ->lockForUpdate()
                        ->value('id');

                    if ($locked === null) {
                        throw ValidationException::withMessages([
                            'tenant' => ['The selected tenant does not exist.'],
                        ]);
                    }

                    // Include soft-deleted quotes so sequence numbers are never reused.
                    $seq = (int) DB::table('quotes')
                            ->where('tenant_id', $tenantId)
                            ->max('quote_seq') + 1;

                    $quote = (new Quote())->forceFill([
                        'quote_seq' => $seq,
                        'quote_number' => 'Q-'.str_pad(
                                (string) $seq,
                                5,
                                '0',
                                STR_PAD_LEFT
                            ),
                        'version' => 1,
                        'status' => Quote::STATUS_DRAFT,
                        'created_by' => $actor->id,
                    ]);
                } else {
                    // The Quote tenant global scope prevents cross-tenant access.
                    $quote = Quote::query()
                        ->lockForUpdate()
                        ->findOrFail($quoteId);

                    if (! $quote->isDraft()) {
                        throw ValidationException::withMessages([
                            'status' => ['Only draft quotes can be edited.'],
                        ]);
                    }
                }

                $notes = trim((string) ($data['notes'] ?? ''));
                $terms = trim((string) ($data['terms'] ?? ''));

                $quote->forceFill([
                    'title' => trim((string) $data['title']),
                    'account_id' => $accountId,
                    'contact_id' => $contactId,
                    'currency' => $data['currency'] ?? 'USD',
                    'valid_until' => $data['valid_until'] ?? null,
                    'notes' => $notes === '' ? null : $notes,
                    'terms' => $terms === '' ? null : $terms,
                    'subtotal' => QuoteTotals::money($totals['subtotal_cents']),
                    'tax_total' => QuoteTotals::money($totals['tax_cents']),
                    'total' => QuoteTotals::money($totals['total_cents']),
                ])->save();

                // Replace existing lines within the current tenant.
                QuoteLine::query()
                    ->where('tenant_id', $tenantId)
                    ->where('quote_id', $quote->id)
                    ->delete();

                foreach ($totals['lines'] as $i => $line) {
                    (new QuoteLine())->forceFill([
                        'quote_id' => $quote->id,
                        'position' => $i,
                        'product_id' => $line['product_id'],
                        'product_variant_id' => $line['variant_id'],
                        'sku' => $line['sku'],
                        'name' => $line['name'],
                        'description' => $line['description'],
                        'quantity' => number_format(
                            (float) $line['quantity'],
                            3,
                            '.',
                            ''
                        ),
                        'unit_price' => QuoteTotals::money($line['unit_cents']),
                        'discount_percent' => number_format(
                            (float) $line['discount_percent'],
                            2,
                            '.',
                            ''
                        ),
                        'tax_category' => $line['tax_category'],
                        'tax_rate' => number_format(
                            $line['rate_bps'] / 10000,
                            4,
                            '.',
                            ''
                        ),
                        'line_total' => QuoteTotals::money($line['net_cents']),
                        'tax_amount' => QuoteTotals::money($line['tax_cents']),
                    ])->save();
                }

                return $quote->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'title' => ['Could not allocate a quote number. Please try again.'],
            ]);
        }
    }

    private function assertOwned(
        ?int $accountId,
        ?int $contactId,
        int $tenantId
    ): void {
        $errors = [];

        $checks = [
            'account_id' => [
                'accounts',
                $accountId,
                'The selected account does not exist.',
            ],
            'contact_id' => [
                'contacts',
                $contactId,
                'The selected contact does not exist.',
            ],
        ];

        foreach ($checks as $field => [$table, $id, $message]) {
            if ($id === null) {
                continue;
            }

            $query = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('id', $id);

            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            if (! $query->exists()) {
                $errors[$field] = [$message];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function resolveLines(array $lines, int $tenantId): array
    {
        $productIds = array_values(array_unique(array_map(
            'intval',
            array_filter(array_column($lines, 'product_id'))
        )));

        $variantIds = array_values(array_unique(array_map(
            'intval',
            array_filter(array_column($lines, 'variant_id'))
        )));

        // Product's Eloquent tenant scope and soft-delete handling apply here.
        $products = $productIds === []
            ? collect()
            : Product::query()
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

        // Only retrieve variants belonging to products resolved for this tenant.
        $resolvedProductIds = $products->keys()->all();

        $variants = ($variantIds === [] || $resolvedProductIds === [])
            ? collect()
            : DB::table('product_variants')
                ->whereIn('id', $variantIds)
                ->whereIn('product_id', $resolvedProductIds)
                ->get()
                ->keyBy('id');

        $errors = [];
        $resolvedLines = [];

        foreach ($lines as $i => $line) {
            $productId = ! empty($line['product_id'])
                ? (int) $line['product_id']
                : null;

            $variantId = ! empty($line['variant_id'])
                ? (int) $line['variant_id']
                : null;

            $product = $productId !== null
                ? $products->get($productId)
                : null;

            $variant = $variantId !== null
                ? $variants->get($variantId)
                : null;

            if ($productId !== null && ($product === null || ! $product->is_active)) {
                $errors["lines.$i.product_id"] = [
                    'This product does not exist or is inactive.',
                ];

                continue;
            }

            if (
                $variantId !== null &&
                (
                    $productId === null ||
                    $variant === null ||
                    (int) $variant->product_id !== $productId ||
                    ! $variant->is_active
                )
            ) {
                $errors["lines.$i.variant_id"] = [
                    'This variant does not exist or is inactive.',
                ];

                continue;
            }

            $explicitPrice = $line['unit_price'] ?? null;

            $explicitPrice = ($explicitPrice === null || $explicitPrice === '')
                ? null
                : $explicitPrice;

            if ($product !== null) {
                $catalogPrice = $variant?->unit_price ?? $product->unit_price;

                $price = $explicitPrice ?? $catalogPrice;
                $sku = $variant?->sku ?? $product->sku;

                $name = $variant !== null
                    ? $product->name.' - '.$variant->name
                    : $product->name;

                $category = $product->tax_category;
            } else {
                $price = $explicitPrice;
                $sku = null;
                $name = trim((string) ($line['name'] ?? ''));
                $category = (string) ($line['tax_category'] ?? '');
            }

            $description = trim((string) ($line['description'] ?? ''));

            $resolvedLines[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'sku' => $sku,
                'name' => $name,
                'description' => $description === '' ? null : $description,
                'quantity' => (float) $line['quantity'],
                'unit_cents' => (int) round((float) $price * 100),
                'discount_percent' => (float) (
                ($line['discount_percent'] ?? '') === ''
                    ? 0
                    : $line['discount_percent']
                ),
                'tax_category' => $category,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolvedLines;
    }
}
