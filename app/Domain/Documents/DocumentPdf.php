<?php

namespace App\Domain\Documents;

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.5: renders a quote or an invoice as a PDF, server-side only. Read-only: it never changes data. The actor must
 * hold the matching .view permission, the lookup carries an explicit tenant predicate (another tenant's id is a
 * 404), and remote resources are disabled in the renderer so nothing is fetched while rendering.
 */
final class DocumentPdf
{
    /** @return array{filename: string, content: string} */
    public function forQuote(User $actor, int $quoteId): array
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('quotes.view');

        $quote = Quote::query()->where('tenant_id', $tenantId)->with('lines')->findOrFail($quoteId);
        $number = $quote->quote_number.($quote->version > 1 ? ' v'.$quote->version : '');

        $meta = [
            ['Status', $this->words($quote->status)],
            ['Version', 'v'.$quote->version],
            ['Valid until', $quote->valid_until?->format('Y-m-d') ?? '-'],
        ];

        return $this->render($tenantId, 'Quote', $number, $quote->title, $quote->currency, $meta, $quote->account_id, $quote->notes, $quote->terms, $quote->lines, $quote->subtotal, $quote->tax_total, $quote->total);
    }

    /** @return array{filename: string, content: string} */
    public function forInvoice(User $actor, int $invoiceId): array
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('invoices.view');

        $invoice = Invoice::query()->where('tenant_id', $tenantId)->with(['lines', 'payments'])->findOrFail($invoiceId);

        $meta = [
            ['Status', $this->words($invoice->status)],
            ['Issue date', $invoice->issue_date?->format('Y-m-d') ?? '-'],
            ['Due date', $invoice->due_date?->format('Y-m-d') ?? '-'],
            ['Payment terms', $invoice->payment_terms_days.' days'],
        ];

        $live = $invoice->payments->filter(fn ($p) => $p->voided_at === null);
        $paidInfo = [
            'paid' => $this->money($invoice->amount_paid, $invoice->currency),
            'balance' => $this->money(max(0, ((float) $invoice->total) - ((float) $invoice->amount_paid)), $invoice->currency),
            'payments' => $live->map(fn ($p) => [
                'date' => $p->paid_on?->format('Y-m-d') ?? '-',
                'method' => $this->words($p->method),
                'reference' => $p->reference ?? '',
                'amount' => $this->money($p->amount, $invoice->currency),
            ])->values()->all(),
        ];

        return $this->render($tenantId, 'Invoice', $invoice->invoice_number, $invoice->title, $invoice->currency, $meta, $invoice->account_id, $invoice->notes, $invoice->terms, $invoice->lines, $invoice->subtotal, $invoice->tax_total, $invoice->total, $paidInfo);
    }

    /**
     * @param list<array{0: string, 1: string}> $meta
     * @param iterable<\App\Models\QuoteLine|\App\Models\InvoiceLine> $lines
     * @return array{filename: string, content: string}
     */
    private function render(int $tenantId, string $label, string $number, string $title, string $currency, array $meta, ?int $accountId, ?string $notes, ?string $terms, iterable $lines, string $subtotal, string $taxTotal, string $total, ?array $paidInfo = null): array
    {
        $rows = [];
        foreach ($lines as $l) {
            $rows[] = [
                'name' => $l->name,
                'sku' => $l->sku,
                'description' => $l->description,
                'quantity' => $this->quantity($l->quantity),
                'unit_price' => $this->money($l->unit_price, $currency),
                'discount' => $this->quantity($l->discount_percent),
                'tax' => $this->money($l->tax_amount, $currency),
                'total' => $this->money($l->line_total, $currency),
            ];
        }

        $html = view('pdf.document', [
            'label' => $label,
            'number' => $number,
            'title' => $title,
            'tenantName' => (string) DB::table('tenants')->where('id', $tenantId)->value('name'),
            'billTo' => $this->accountName($tenantId, $accountId),
            'meta' => $meta,
            'lines' => $rows,
            'subtotal' => $this->money($subtotal, $currency),
            'taxTotal' => $this->money($taxTotal, $currency),
            'total' => $this->money($total, $currency),
            'notes' => $notes,
            'terms' => $terms,
            'paidInfo' => $paidInfo,
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return [
            'filename' => preg_replace('/[^A-Za-z0-9._-]+/', '-', $number).'.pdf',
            'content' => (string) $dompdf->output(),
        ];
    }

    private function accountName(int $tenantId, ?int $accountId): ?string
    {
        if ($accountId === null || ! Schema::hasColumn('accounts', 'name')) {
            return null;
        }

        $query = DB::table('accounts')->where('tenant_id', $tenantId)->where('id', $accountId);
        if (Schema::hasColumn('accounts', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $name = $query->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function words(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }

    private function money(mixed $value, string $currency): string
    {
        return $currency.' '.number_format((float) $value, 2, '.', ',');
    }

    /** 2.000 -> 2, 1.500 -> 1.5, 0.00 -> 0 */
    private function quantity(mixed $value): string
    {
        $s = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }
}
