<?php

namespace App\Http\Resources;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A quote version as the SPA sees it (10.1). Explicit whitelist: no tenant id. Money is a decimal string.
 * Lines appear only when loaded (show/store/update); the list carries lines_count instead.
 * Line "variant_id" mirrors the request payload key (stored as product_variant_id).
 *
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_seq' => $this->quote_seq,
            'quote_number' => $this->quote_number,
            'version' => $this->version,
            'status' => $this->status,
            'lock_version' => $this->lock_version,
            'title' => $this->title,
            'account_id' => $this->account_id,
            'contact_id' => $this->contact_id,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'notes' => $this->notes,
            'terms' => $this->terms,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'created_by' => $this->created_by,
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'position' => $l->position,
                'product_id' => $l->product_id,
                'variant_id' => $l->product_variant_id,
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
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
