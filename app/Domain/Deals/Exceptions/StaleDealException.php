<?php

namespace App\Domain\Deals\Exceptions;

use App\Models\Deal;
use RuntimeException;


class StaleDealException extends RuntimeException
{
    public function __construct(public readonly Deal $deal)
    {
        parent::__construct('Deal has been modified since it was loaded (lock_version mismatch).');
    }
}
