<?php

namespace App\Domain\Purchasing\Exceptions;

use App\Models\PurchaseOrder;
use RuntimeException;

/** 11.2a: lock_version mismatch. The API layer answers 409 and carries the current PO (state-machine pattern 3.0). */
final class PurchaseOrderTransitionException extends RuntimeException
{
    public function __construct(public readonly PurchaseOrder $current)
    {
        parent::__construct('This purchase order was updated by someone else. Refresh to see the latest.');
    }

    public static function stale(PurchaseOrder $current): self
    {
        return new self($current);
    }
}
