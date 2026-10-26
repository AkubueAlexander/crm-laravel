<?php

namespace App\Domain\Marketing\Exceptions;

use App\Models\Automation;
use RuntimeException;

/**
 * One exception for every refused automation transition (same shape as CampaignTransitionException).
 * `reason` is the stable machine code the controller puts in the JSON body.
 */
final class AutomationTransitionException extends RuntimeException
{
    public const STALE = 'stale_automation';

    public const INVALID = 'invalid_transition';

    public const DEFINITION_INVALID = 'automation_invalid';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?Automation $automation = null,
    ) {
        parent::__construct($message);
    }

    /** 3.0 / 409: carries the locked, current server-side automation so the client can reconcile. */
    public static function stale(Automation $automation): self
    {
        return new self(
            self::STALE,
            'This automation was updated by someone else - refresh to see the latest version.',
            $automation,
        );
    }

    public static function invalid(string $from, string $to): self
    {
        return new self(self::INVALID, "An automation cannot move from {$from} to {$to}.");
    }

    public static function definitionInvalid(string $message): self
    {
        return new self(self::DEFINITION_INVALID, $message);
    }

    public function isStale(): bool
    {
        return $this->reason === self::STALE;
    }
}