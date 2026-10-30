<?php

namespace App\Domain\Hr\Hris;

/** 13.3: default adapter. No HRIS is connected, so there is nothing to fetch. */
final class NullHrisAdapter implements HrisAdapter
{
    public function name(): string
    {
        return 'null';
    }

    public function fetchEmployees(): array
    {
        return [];
    }
}
