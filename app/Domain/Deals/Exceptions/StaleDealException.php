<?php

namespace App\Domain\Deals\Exceptions;

use App\Models\Deal;
use RuntimeException;

/**
 * 3.2: thrown when a transition's lock_version doesn't match the deal's
 * current server-side value — the concurrency case. The controller catches
 * this specifically and returns 409 with the current state, per the
 * "someone else moved it first" UX the blueprint specifies.
 */
class StaleDealException extends RuntimeException
{
    public function __construct(public readonly Deal $deal)
    {
        parent::__construct('Deal has been modified since it was loaded (lock_version mismatch).');
    }
}
