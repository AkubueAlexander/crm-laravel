<?php

namespace App\Domain\Accounting\Sync;

use RuntimeException;

/** 12.2: placeholder for a provider with no real integration yet. Every push fails with a clear message. */
final class UnconfiguredLedgerSyncAdapter implements LedgerSyncAdapter
{
    public function __construct(private readonly string $label)
    {
    }

    public function pushAccounts(array $accounts): int
    {
        throw new RuntimeException("{$this->label} is not connected yet. Choose the log provider until the integration is set up.");
    }

    public function pushEntries(array $entries): int
    {
        throw new RuntimeException("{$this->label} is not connected yet. Choose the log provider until the integration is set up.");
    }
}
