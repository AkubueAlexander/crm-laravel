<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invoice as the SPA sees it (10.4). Explicit whitelist: no tenant id. Money is a decimal string.
 * Lines appear only when loaded (show/store); the list carries lines_count instead.
 * Line "variant_id" mirrors the quote resource (stored as product_variant_id).
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_seq' => $this->invoice_seq,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'lock_version' => $this->lock_version,
            'quote_id' => $this->quote_id,
            'title' => $this->title,
            'account_id' => $this->account_id,
            'contact_id' => $this->contact_id,
            'currency' => $this->currency,
            'payment_terms_days' => $this->payment_terms_days,
            'issue_date' => $this->issue_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'terms' => $this->terms,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance' => number_format(((int) round(((float) $this->total) * 100) - (int) round(((float) $this->amount_paid) * 100)) / 100, 2, '.', ''),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
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
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => (new PaymentResource($p))->resolve($request))->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
