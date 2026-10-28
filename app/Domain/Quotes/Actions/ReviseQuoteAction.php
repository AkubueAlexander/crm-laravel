<?php

namespace App\Domain\Quotes\Actions;

use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.2: revise a quote that left draft. The revision is a NEW ROW: same quote_seq and number, version + 1, status
 * draft, lines copied as the snapshot they were (no re-pricing: it starts from exactly what was quoted). The
 * source version is never touched, so what was sent stays reproducible. Only the latest version can be revised.
 */
final class ReviseQuoteAction
{
    public function execute(User $actor, int $quoteId): Quote
    {
        Gate::forUser($actor)->authorize('quotes.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        try {
            return DB::transaction(function () use ($actor, $quoteId, $tenantId): Quote {
                // Serialises version allocation per tenant (same lock as SaveQuoteAction).
                DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->value('id');

                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $source = Quote::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($quoteId);

                if ($source->isDraft()) {
                    throw ValidationException::withMessages(['status' => ['A draft can be edited directly. Revise a quote only after it left draft.']]);
                }

                $latest = (int) DB::table('quotes')
                    ->where('tenant_id', $tenantId)
                    ->where('quote_seq', $source->quote_seq)
                    ->max('version');

                if ($source->version !== $latest) {
                    throw ValidationException::withMessages(['version' => ['Only the latest version can be revised.']]);
                }

                $revision = (new Quote())->forceFill([
                    'tenant_id' => $tenantId,
                    'quote_seq' => $source->quote_seq,
                    'quote_number' => $source->quote_number,
                    'version' => $source->version + 1,
                    'status' => Quote::STATUS_DRAFT,
                    'lock_version' => 0,
                    'title' => $source->title,
                    'account_id' => $source->account_id,
                    'contact_id' => $source->contact_id,
                    'currency' => $source->currency,
                    'valid_until' => $source->valid_until?->format('Y-m-d'),
                    'notes' => $source->notes,
                    'terms' => $source->terms,
                    'subtotal' => $source->subtotal,
                    'tax_total' => $source->tax_total,
                    'total' => $source->total,
                    'created_by' => $actor->id,
                ]);
                $revision->save();

                $lines = QuoteLine::query()
                    ->where('tenant_id', $tenantId)
                    ->where('quote_id', $source->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get();

                foreach ($lines as $l) {
                    (new QuoteLine())->forceFill([
                        'tenant_id' => $tenantId,
                        'quote_id' => $revision->id,
                        'position' => $l->position,
                        'product_id' => $l->product_id,
                        'product_variant_id' => $l->product_variant_id,
                        'sku' => $l->sku,
                        'name' => $l->name,
                        'description' => $l->description,
                        'quantity' => $l->quantity,
                        'unit_price' => $l->unit_price,
                        'discount_percent' => $l->discount_percent,
                        'tax_category' => $l->tax_category,
                        'tax_rate' => $l->tax_rate,
                        'line_total' => $l->line_total,
                        'tax_amount' => $l->tax_amount,
                    ])->save();
                }

                return $revision->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            // Race guard: the tenant lock makes this very unlikely. The caller can simply retry.
            throw ValidationException::withMessages(['version' => ['Could not create the new version. Please try again.']]);
        }
    }
}
