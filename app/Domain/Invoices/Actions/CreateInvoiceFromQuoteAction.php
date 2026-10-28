<?php

namespace App\Domain\Invoices\Actions;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.4a: turns an ACCEPTED quote into a draft invoice (snapshot of lines and amounts as quoted). Tenant, number,
 * status and every amount come from the server (1.3); the caller only picks the quote and the payment terms.
 * One live (non-void) invoice per quote: a voided one can be re-invoiced.
 *
 * Tax seam: amounts are carried over from the quote. A TaxJar/Avalara calculation would be applied here, server-side.
 */
final class CreateInvoiceFromQuoteAction
{
    public const DEFAULT_TERMS_DAYS = 30;

    public function execute(User $actor, int $quoteId, ?int $paymentTermsDays = null): Invoice
    {
        Gate::forUser($actor)->authorize('invoices.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        $terms = $paymentTermsDays ?? self::DEFAULT_TERMS_DAYS;
        if ($terms < 0 || $terms > 365) {
            throw ValidationException::withMessages(['payment_terms_days' => ['Payment terms must be between 0 and 365 days.']]);
        }

        try {
            return DB::transaction(function () use ($actor, $quoteId, $tenantId, $terms): Invoice {
                // Serialises invoice-number allocation per tenant.
                DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->value('id');

                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $quote = Quote::query()->where('tenant_id', $tenantId)->findOrFail($quoteId);

                if ($quote->status !== 'accepted') {
                    throw ValidationException::withMessages(['quote_id' => ['Only accepted quotes can be invoiced.']]);
                }

                $alreadyInvoiced = Invoice::query()
                    ->where('tenant_id', $tenantId)
                    ->where('quote_seq', $quote->quote_seq)
                    ->where('status', '<>', 'void')
                    ->exists();

                if ($alreadyInvoiced) {
                    throw ValidationException::withMessages(['quote_id' => ['This quote already has an invoice.']]);
                }

                $seq = (int) DB::table('invoices')->where('tenant_id', $tenantId)->max('invoice_seq') + 1;

                $invoice = (new Invoice())->forceFill([
                    'tenant_id' => $tenantId,
                    'invoice_seq' => $seq,
                    'invoice_number' => 'INV-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                    'status' => 'draft',
                    'quote_id' => $quote->id,
                    'quote_seq' => $quote->quote_seq,
                    'title' => $quote->title,
                    'account_id' => $quote->account_id,
                    'contact_id' => $quote->contact_id,
                    'currency' => $quote->currency,
                    'payment_terms_days' => $terms,
                    'notes' => $quote->notes,
                    'terms' => $quote->terms,
                    'subtotal' => $quote->subtotal,
                    'tax_total' => $quote->tax_total,
                    'total' => $quote->total,
                    'created_by' => $actor->id,
                ]);
                $invoice->save();

                $lines = QuoteLine::query()
                    ->where('tenant_id', $tenantId)
                    ->where('quote_id', $quote->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get();

                foreach ($lines as $i => $l) {
                    (new InvoiceLine())->forceFill([
                        'tenant_id' => $tenantId,
                        'invoice_id' => $invoice->id,
                        'position' => $i,
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

                return $invoice->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            // Race guard: a concurrent create won (same number or same quote). The caller can simply retry.
            throw ValidationException::withMessages(['quote_id' => ['Could not create the invoice. Please try again.']]);
        }
    }
}
