<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Sync\LedgerSyncProviders;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** 12.2: chooses the tenant's ledger sync provider (one settings row per tenant, upserted). Needs accounting.manage. */
final class SaveLedgerSyncSettingsAction
{
    public function execute(User $actor, string $provider): string
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('accounting.manage');

        $provider = trim($provider);
        if (! in_array($provider, LedgerSyncProviders::ids(), true)) {
            throw ValidationException::withMessages(['provider' => ['Choose a sync provider.']]);
        }

        DB::table('ledger_sync_settings')->upsert(
            [['tenant_id' => $tenantId, 'provider' => $provider, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]],
            ['tenant_id'],
            ['provider', 'updated_by', 'updated_at'],
        );

        return $provider;
    }
}
