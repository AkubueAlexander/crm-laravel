<?php

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Actions\PostJournalEntryAction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 12.3: posts invoice and payment activity to the ledger, inside the caller's transaction (same commit as the invoice
 * write). Issue: Dr Receivable / Cr Revenue / Cr Sales tax. Payment: Dr Cash / Cr Receivable. Voiding an issued invoice
 * or reversing a payment posts a reversing entry (the original is never edited). Entries carry source_type/source_id.
 *
 * Quiet by design: a tenant without the default chart codes (1000, 1100, 2200, 4000) or with one of them inactive gets
 * no postings, and a posting that fails validation is logged and skipped; the invoice workflow is never blocked by the
 * ledger. Idempotent per source (an invoice or payment is posted once). Runs without accounting.manage: the caller has
 * already passed invoices.manage and the amounts come from the server's own invoice rows. Balanced by construction:
 * revenue = total - tax. The ledger is single-currency; the invoice currency is not converted.
 */
final class InvoiceLedgerPoster
{
    public const CASH = '1000';

    public const RECEIVABLE = '1100';

    public const SALES_TAX = '2200';

    public const REVENUE = '4000';

    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_PAYMENT = 'payment';

    public const SOURCE_INVOICE_VOID = 'invoice_void';

    public const SOURCE_PAYMENT_VOID = 'payment_void';

    public function afterTransition(User $actor, Invoice $invoice, string $toStatus, ?Payment $settlement): void
    {
        if ($toStatus === 'issued') {
            $this->invoiceIssued($actor, $invoice);
        } elseif ($toStatus === 'paid' && $settlement !== null) {
            $this->paymentRecorded($actor, $settlement, $invoice);
        } elseif ($toStatus === 'void') {
            $this->invoiceVoided($actor, $invoice);
        }
    }

    public function invoiceIssued(User $actor, Invoice $invoice): void
    {
        $tenantId = (int) $actor->tenant_id;
        $total = $this->cents($invoice->total);
        $tax = $this->cents($invoice->tax_total);
        $revenue = $total - $tax;

        if ($total <= 0 || $tax < 0 || $revenue <= 0) {
            return;
        }

        $codes = [self::RECEIVABLE, self::REVENUE];
        if ($tax > 0) {
            $codes[] = self::SALES_TAX;
        }
        $accounts = $this->accounts($tenantId, $codes);
        if ($accounts === null || $this->hasEntry($tenantId, self::SOURCE_INVOICE, (int) $invoice->id)) {
            return;
        }

        $lines = [
            ['account_id' => $accounts[self::RECEIVABLE], 'debit' => $this->money($total), 'credit' => '0.00', 'description' => $invoice->invoice_number],
            ['account_id' => $accounts[self::REVENUE], 'debit' => '0.00', 'credit' => $this->money($revenue), 'description' => $invoice->invoice_number],
        ];
        if ($tax > 0) {
            $lines[] = ['account_id' => $accounts[self::SALES_TAX], 'debit' => '0.00', 'credit' => $this->money($tax), 'description' => $invoice->invoice_number];
        }

        $date = $invoice->issue_date?->toDateString() ?? now()->toDateString();
        $this->post($actor, $date, "Invoice {$invoice->invoice_number} issued", $lines, self::SOURCE_INVOICE, (int) $invoice->id, null);
    }

    public function paymentRecorded(User $actor, Payment $payment, Invoice $invoice): void
    {
        $tenantId = (int) $actor->tenant_id;
        $amount = $this->cents($payment->amount);
        if ($amount <= 0) {
            return;
        }

        $accounts = $this->accounts($tenantId, [self::CASH, self::RECEIVABLE]);
        if ($accounts === null || $this->hasEntry($tenantId, self::SOURCE_PAYMENT, (int) $payment->id)) {
            return;
        }

        $lines = [
            ['account_id' => $accounts[self::CASH], 'debit' => $this->money($amount), 'credit' => '0.00', 'description' => $invoice->invoice_number],
            ['account_id' => $accounts[self::RECEIVABLE], 'debit' => '0.00', 'credit' => $this->money($amount), 'description' => $invoice->invoice_number],
        ];

        $date = $payment->paid_on?->toDateString() ?? now()->toDateString();
        $this->post($actor, $date, "Payment on {$invoice->invoice_number}", $lines, self::SOURCE_PAYMENT, (int) $payment->id, null);
    }

    public function invoiceVoided(User $actor, Invoice $invoice): void
    {
        $this->reverse($actor, self::SOURCE_INVOICE, (int) $invoice->id, "Invoice {$invoice->invoice_number} voided", self::SOURCE_INVOICE_VOID);
    }

    public function paymentVoided(User $actor, Payment $payment, Invoice $invoice): void
    {
        $this->reverse($actor, self::SOURCE_PAYMENT, (int) $payment->id, "Payment reversed on {$invoice->invoice_number}", self::SOURCE_PAYMENT_VOID);
    }

    private function reverse(User $actor, string $sourceType, int $sourceId, string $reason, string $reverseSourceType): void
    {
        $tenantId = (int) $actor->tenant_id;

        $entry = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('reverses_entry_id')
            ->first();
        if ($entry === null) {
            return;
        }

        $already = JournalEntry::query()->where('tenant_id', $tenantId)->where('reverses_entry_id', $entry->id)->exists();
        if ($already) {
            return;
        }

        $lines = JournalLine::query()
            ->where('tenant_id', $tenantId)
            ->where('journal_entry_id', $entry->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (JournalLine $l) => [
                'account_id' => $l->ledger_account_id,
                'debit' => (string) $l->credit,
                'credit' => (string) $l->debit,
                'description' => $l->description,
            ])
            ->all();

        $this->post($actor, now()->toDateString(), "Reversal of {$entry->entry_number}: {$reason}", $lines, $reverseSourceType, $sourceId, (int) $entry->id);
    }

    /** @param list<array<string, mixed>> $lines */
    private function post(User $actor, string $date, string $memo, array $lines, string $sourceType, int $sourceId, ?int $reversesEntryId): void
    {
        try {
            app(PostJournalEntryAction::class)->execute($actor, $date, $memo, $lines, $sourceType, $sourceId, $reversesEntryId, false);
        } catch (ValidationException $e) {
            Log::warning('Ledger auto-posting skipped', ['source_type' => $sourceType, 'source_id' => $sourceId, 'errors' => $e->errors()]);
        }
    }

    private function hasEntry(int $tenantId, string $sourceType, int $sourceId): bool
    {
        return JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('reverses_entry_id')
            ->exists();
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, int>|null code => account id, or null when any code is missing or inactive
     */
    private function accounts(int $tenantId, array $codes): ?array
    {
        $found = LedgerAccount::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('code', $codes)
            ->where('is_active', true)
            ->pluck('id', 'code')
            ->all();

        foreach ($codes as $code) {
            if (! isset($found[$code])) {
                return null;
            }
        }

        return array_map('intval', $found);
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
