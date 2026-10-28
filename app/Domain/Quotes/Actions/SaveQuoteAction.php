<?php

namespace App\Domain\Quotes\Actions;

use App\Domain\Quotes\QuoteTotals;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.1: create ($quoteId null) or update a DRAFT quote with its lines, in one transaction.
 * Lines are replaced wholesale. Tenant, number, version, status and every total come from the server (1.3):
 * the request body can only say what is sold, never what it costs in total. Catalog lines take sku/name/tax
 * category from the catalog; the unit price defaults to the variant/product price but may be overridden.
 */
final class SaveQuoteAction
{
    private const MAX_LINES = 100;

    /**
     * @param array{title: string, currency?: string, account_id?: int|null, contact_id?: int|null, valid_until?: string|null, notes?: string|null, terms?: string|null, lines?: list<array<string, mixed>>} $data
     */
    public function execute(User $actor, ?int $quoteId, array $data): Quote
    {
        Gate::forUser($actor)->authorize('quotes.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        $lines = array_values($data['lines'] ?? []);
        $this->assertFields($data, $lines);

        $accountId = isset($data['account_id']) ? (int) $data['account_id'] : null;
        $contactId = isset($data['contact_id']) ? (int) $data['contact_id'] : null;
        $this->assertOwned($tenantId, $accountId, $contactId);

        $totals = QuoteTotals::compute($this->resolveLines($tenantId, $lines));

        try {
            return DB::transaction(function () use ($actor, $quoteId, $tenantId, $data, $accountId, $contactId, $totals): Quote {
                // Serialises quote-number allocation and edits per tenant.
                DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->value('id');

                if ($quoteId === null) {
                    $seq = (int) DB::table('quotes')->where('tenant_id', $tenantId)->max('quote_seq') + 1;
                    $quote = (new Quote())->forceFill([
                        'tenant_id' => $tenantId,
                        'quote_seq' => $seq,
                        'quote_number' => 'Q-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                        'version' => 1,
                        'status' => Quote::STATUS_DRAFT,
                        'created_by' => $actor->id,
                    ]);
                } else {
                    // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                    $quote = Quote::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($quoteId);

                    if (! $quote->isDraft()) {
                        throw ValidationException::withMessages(['status' => ['Only draft quotes can be edited.']]);
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

                QuoteLine::query()->where('tenant_id', $tenantId)->where('quote_id', $quote->id)->delete();

                foreach ($totals['lines'] as $i => $l) {
                    (new QuoteLine())->forceFill([
                        'tenant_id' => $tenantId,
                        'quote_id' => $quote->id,
                        'position' => $i,
                        'product_id' => $l['product_id'],
                        'product_variant_id' => $l['variant_id'],
                        'sku' => $l['sku'],
                        'name' => $l['name'],
                        'description' => $l['description'],
                        'quantity' => number_format((float) $l['quantity'], 3, '.', ''),
                        'unit_price' => QuoteTotals::money($l['unit_cents']),
                        'discount_percent' => number_format((float) $l['discount_percent'], 2, '.', ''),
                        'tax_category' => $l['tax_category'],
                        'tax_rate' => number_format($l['rate_bps'] / 10000, 4, '.', ''),
                        'line_total' => QuoteTotals::money($l['net_cents']),
                        'tax_amount' => QuoteTotals::money($l['tax_cents']),
                    ])->save();
                }

                return $quote->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            // Race guard: two creates allocated the same number. The caller can simply retry.
            throw ValidationException::withMessages(['title' => ['Could not allocate a quote number. Please try again.']]);
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function assertFields(array $data, array $lines): void
    {
        $errors = [];

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = ['Title is required (max 160 characters).'];
        }

        if (! preg_match('/^[A-Z]{3}$/', (string) ($data['currency'] ?? 'USD'))) {
            $errors['currency'] = ['Use a 3-letter currency code, e.g. USD.'];
        }

        $validUntil = $data['valid_until'] ?? null;
        if ($validUntil !== null && $validUntil !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', (string) $validUntil);
            if (! $d || $d->format('Y-m-d') !== (string) $validUntil) {
                $errors['valid_until'] = ['Use a valid date (YYYY-MM-DD).'];
            }
        }

        if (count($lines) > self::MAX_LINES) {
            $errors['lines'] = ['A quote can have at most '.self::MAX_LINES.' lines.'];
        }

        foreach ($lines as $i => $l) {
            $qty = $l['quantity'] ?? null;
            if (! is_numeric($qty) || (float) $qty <= 0 || (float) $qty > 999999.999) {
                $errors["lines.$i.quantity"] = ['Quantity must be greater than 0.'];
            }

            $discount = $l['discount_percent'] ?? 0;
            if ($discount === null || $discount === '') {
                $discount = 0;
            }
            if (! is_numeric($discount) || (float) $discount < 0 || (float) $discount > 100) {
                $errors["lines.$i.discount_percent"] = ['Discount must be between 0 and 100.'];
            }

            $price = $l['unit_price'] ?? null;
            $hasPrice = $price !== null && $price !== '';
            $isCatalog = ! empty($l['product_id']);

            if ($hasPrice && (! is_numeric($price) || (float) $price < 0 || (float) $price > 9999999999.99)) {
                $errors["lines.$i.unit_price"] = ['Enter a valid price.'];
            }

            if (! $isCatalog) {
                if (! $hasPrice) {
                    $errors["lines.$i.unit_price"] ??= ['Enter a price.'];
                }
                $name = trim((string) ($l['name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 160) {
                    $errors["lines.$i.name"] = ['Name is required (max 160 characters).'];
                }
                if (! in_array($l['tax_category'] ?? null, Product::TAX_CATEGORIES, true)) {
                    $errors["lines.$i.tax_category"] = ['Unknown tax category.'];
                }
            }

            if (mb_strlen((string) ($l['description'] ?? '')) > 2000) {
                $errors["lines.$i.description"] = ['Description must be 2000 characters or fewer.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertOwned(int $tenantId, ?int $accountId, ?int $contactId): void
    {
        $errors = [];

        foreach (['account_id' => ['accounts', $accountId, 'The selected account does not exist.'], 'contact_id' => ['contacts', $contactId, 'The selected contact does not exist.']] as $field => [$table, $id, $message]) {
            if ($id === null) {
                continue;
            }

            $query = DB::table($table)->where('tenant_id', $tenantId)->where('id', $id);
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
     * Resolves catalog references (tenant-owned, not deleted, active) and fills the line snapshot.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function resolveLines(int $tenantId, array $lines): array
    {
        $productIds = array_values(array_unique(array_map('intval', array_filter(array_column($lines, 'product_id')))));
        $variantIds = array_values(array_unique(array_map('intval', array_filter(array_column($lines, 'variant_id')))));

        $products = $productIds === [] ? collect() : DB::table('products')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('id', $productIds)->get()->keyBy('id');
        $variants = $variantIds === [] ? collect() : DB::table('product_variants')
            ->where('tenant_id', $tenantId)->whereIn('id', $variantIds)->get()->keyBy('id');

        $errors = [];
        $out = [];

        foreach ($lines as $i => $l) {
            $pid = ! empty($l['product_id']) ? (int) $l['product_id'] : null;
            $vid = ! empty($l['variant_id']) ? (int) $l['variant_id'] : null;
            $product = $pid !== null ? $products->get($pid) : null;
            $variant = $vid !== null ? $variants->get($vid) : null;

            if ($pid !== null && ($product === null || ! $product->is_active)) {
                $errors["lines.$i.product_id"] = ['This product does not exist or is inactive.'];
                continue;
            }

            if ($vid !== null && ($pid === null || $variant === null || (int) $variant->product_id !== $pid || ! $variant->is_active)) {
                $errors["lines.$i.variant_id"] = ['This variant does not exist or is inactive.'];
                continue;
            }

            $explicit = $l['unit_price'] ?? null;
            $explicit = ($explicit === null || $explicit === '') ? null : $explicit;

            if ($product !== null) {
                $catalogPrice = $variant?->unit_price ?? $product->unit_price;
                $price = $explicit ?? $catalogPrice;
                $sku = $variant?->sku ?? $product->sku;
                $name = $variant !== null ? $product->name.' - '.$variant->name : $product->name;
                $category = $product->tax_category;
            } else {
                $price = $explicit;
                $sku = null;
                $name = trim((string) $l['name']);
                $category = (string) $l['tax_category'];
            }

            $description = trim((string) ($l['description'] ?? ''));

            $out[] = [
                'product_id' => $pid,
                'variant_id' => $vid,
                'sku' => $sku,
                'name' => $name,
                'description' => $description === '' ? null : $description,
                'quantity' => (float) $l['quantity'],
                'unit_cents' => (int) round((float) $price * 100),
                'discount_percent' => (float) (($l['discount_percent'] ?? '') === '' ? 0 : ($l['discount_percent'] ?? 0)),
                'tax_category' => $category,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }
}
