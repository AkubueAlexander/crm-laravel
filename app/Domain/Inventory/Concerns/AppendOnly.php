<?php

namespace App\Domain\Inventory\Concerns;

use LogicException;

/**
 * 11.0a: makes a model append-only. Any update or delete through Eloquent throws, so the stock ledger can only
 * ever grow. (Raw query-builder writes bypass model events: nothing in the code base does that to this table.)
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function (): void {
            throw new LogicException('The stock ledger is append-only: rows are never edited.');
        });

        static::deleting(function (): void {
            throw new LogicException('The stock ledger is append-only: rows are never deleted.');
        });
    }
}
