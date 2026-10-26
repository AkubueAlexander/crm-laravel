<?php

namespace App\Domain\Marketing\Exceptions;

use App\Models\Campaign;
use RuntimeException;

/**
 * One exception for every refused campaign transition. `reason` is the stable machine code the
 * controller puts in the JSON body (same { message, errors, code } shape as the Deal 409/422).
 */
final class CampaignTransitionException extends RuntimeException
{
    public const STALE = 'stale_campaign';

    public const INVALID = 'invalid_transition';

    public const AUDIENCE_MISSING = 'audience_missing';

    public const SCHEDULE_INVALID = 'schedule_invalid';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?Campaign $campaign = null,
    ) {
        parent::__construct($message);
    }

    /** 3.0 / 409: carries the locked, current server-side campaign so the client can reconcile. */
    public static function stale(Campaign $campaign): self
    {
        return new self(
            self::STALE,
            'This campaign was updated by someone else - refresh to see the latest status.',
            $campaign,
        );
    }

    public static function invalid(string $from, string $to): self
    {
        return new self(self::INVALID, "A campaign cannot move from {$from} to {$to}.");
    }

    public static function audienceMissing(): self
    {
        return new self(
            self::AUDIENCE_MISSING,
            'Choose a segment and an email template before scheduling or sending this campaign.',
        );
    }

    public static function scheduleInvalid(string $message): self
    {
        return new self(self::SCHEDULE_INVALID, $message);
    }

    public function isStale(): bool
    {
        return $this->reason === self::STALE;
    }
}