<?php

namespace App\Domain\Leads\Exceptions;

use App\Models\Lead;
use RuntimeException;

/**
 * Thrown by ConvertLeadAction when a lead cannot be converted. The controller
 * maps `reason` onto the { message, errors, code } error shape (14.3).
 */
final class LeadNotConvertibleException extends RuntimeException
{
    public const ALREADY_CONVERTED = 'already_converted';

    public const MISSING_LAST_NAME = 'missing_last_name';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly Lead $lead,
    ) {
        parent::__construct($message);
    }

    public static function alreadyConverted(Lead $lead): self
    {
        return new self(
            self::ALREADY_CONVERTED,
            'This lead has already been converted. Refresh to see its latest state.',
            $lead,
        );
    }

    public static function missingLastName(Lead $lead): self
    {
        return new self(
            self::MISSING_LAST_NAME,
            'Add a last name to this lead before converting it. Contacts require one.',
            $lead,
        );
    }
}
