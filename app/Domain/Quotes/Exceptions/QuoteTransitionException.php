<?php

namespace App\Domain\Quotes\Exceptions;

use App\Models\Quote;
use RuntimeException;

/**
 * One exception for every refused quote transition. `reason` is the stable machine code the controller puts in the
 * JSON body (same { message, errors, code } shape as the campaign/deal 409/422).
 */
final class QuoteTransitionException extends RuntimeException
{
    public const STALE = 'stale_quote';

    public const INVALID = 'invalid_transition';

    public const EMPTY_QUOTE = 'quote_empty';

    public const NOTE_REQUIRED = 'note_required';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?Quote $quote = null,
    ) {
        parent::__construct($message);
    }

    /** 3.0 / 409: carries the locked, current server-side quote so the client can reconcile. */
    public static function stale(Quote $quote): self
    {
        return new self(self::STALE, 'This quote was updated by someone else - refresh to see the latest status.', $quote);
    }

    public static function invalid(string $from, string $to): self
    {
        return new self(self::INVALID, "A quote cannot move from {$from} to {$to}.");
    }

    public static function emptyQuote(): self
    {
        return new self(self::EMPTY_QUOTE, 'Add at least one line before submitting this quote for approval.');
    }

    public static function noteRequired(): self
    {
        return new self(self::NOTE_REQUIRED, 'Give a reason when rejecting a quote.');
    }

    public function isStale(): bool
    {
        return $this->reason === self::STALE;
    }
}
