<?php

namespace App\Domain\Hr\Exceptions;

use App\Models\LeaveRequest;
use RuntimeException;

/** One exception for every refused leave-request change; `reason` is the stable machine code for the JSON body. */
final class LeaveTransitionException extends RuntimeException
{
    public const STALE = 'stale_leave_request';

    public const INVALID = 'invalid_transition';

    public const NOTE_REQUIRED = 'note_required';

    public const NOT_EDITABLE = 'not_editable';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?LeaveRequest $leave = null,
    ) {
        parent::__construct($message);
    }

    /** 3.0 / 409: carries the locked, current server-side row so the client can reconcile. */
    public static function stale(LeaveRequest $leave): self
    {
        return new self(self::STALE, 'This leave request was updated by someone else - refresh to see the latest status.', $leave);
    }

    public static function invalid(string $from, string $to): self
    {
        return new self(self::INVALID, "A leave request cannot move from {$from} to {$to}.");
    }

    public static function noteRequired(): self
    {
        return new self(self::NOTE_REQUIRED, 'Give a reason when rejecting a leave request.');
    }

    public static function notEditable(): self
    {
        return new self(self::NOT_EDITABLE, 'Only a pending leave request can be edited.');
    }

    public function isStale(): bool
    {
        return $this->reason === self::STALE;
    }
}
