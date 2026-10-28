<?php

namespace App\Domain\Invoices\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * One exception for every refused invoice transition. `reason` is the stable machine code the controller puts in
 * the JSON body (same { message, errors, code } shape as the quote/campaign/deal 409/422).
 */
final class InvoiceTransitionException extends RuntimeException
{
    public const STALE = 'stale_invoice';

    public const INVALID = 'invalid_transition';

    public const NOTE_REQUIRED = 'note_required';

    public const HAS_PAYMENTS = 'has_payments';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?Invoice $invoice = null,
    ) {
        parent::__construct($message);
    }

    /** 3.0 / 409: carries the locked, current server-side invoice so the client can reconcile. */
    public static function stale(Invoice $invoice): self
    {
        return new self(self::STALE, 'This invoice was updated by someone else - refresh to see the latest status.', $invoice);
    }

    public static function invalid(string $from, string $to): self
    {
        return new self(self::INVALID, "An invoice cannot move from {$from} to {$to}.");
    }

    public static function noteRequired(): self
    {
        return new self(self::NOTE_REQUIRED, 'Give a reason when voiding an invoice.');
    }

    public static function hasPayments(): self
    {
        return new self(self::HAS_PAYMENTS, 'Reverse the recorded payments before voiding this invoice.');
    }

    public function isStale(): bool
    {
        return $this->reason === self::STALE;
    }
}
