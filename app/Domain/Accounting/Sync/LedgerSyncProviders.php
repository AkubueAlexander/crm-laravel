<?php

namespace App\Domain\Accounting\Sync;

use Illuminate\Support\Facades\DB;

/** 12.2: the provider catalog, the tenant's current choice, and the adapter factory. */
final class LedgerSyncProviders
{
    public const DEFAULT = 'log';

    /** @return list<array{id: string, label: string, configured: bool}> */
    public static function catalog(): array
    {
        return [
            ['id' => 'log', 'label' => 'Log only (no external calls)', 'configured' => true],
            ['id' => 'quickbooks', 'label' => 'QuickBooks', 'configured' => false],
            ['id' => 'xero', 'label' => 'Xero', 'configured' => false],
        ];
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_column(self::catalog(), 'id');
    }

    /** The tenant's chosen provider; falls back to the log provider when unset or unknown. */
    public static function current(int $tenantId): string
    {
        $provider = DB::table('ledger_sync_settings')->where('tenant_id', $tenantId)->value('provider');

        return is_string($provider) && in_array($provider, self::ids(), true) ? $provider : self::DEFAULT;
    }

    public static function adapter(string $provider): LedgerSyncAdapter
    {
        return match ($provider) {
            'log' => app(LogLedgerSyncAdapter::class),
            'quickbooks' => new UnconfiguredLedgerSyncAdapter('QuickBooks'),
            'xero' => new UnconfiguredLedgerSyncAdapter('Xero'),
            default => new UnconfiguredLedgerSyncAdapter($provider),
        };
    }
}
