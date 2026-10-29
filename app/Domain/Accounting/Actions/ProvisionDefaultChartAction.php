<?php

namespace App\Domain\Accounting\Actions;

use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * 12.0b: loads a small starter chart of accounts. Idempotent: only codes the tenant does not have yet are created,
 * and existing accounts are never touched. Goes through SaveLedgerAccountAction so validation is the same. Needs
 * accounting.manage. Returns how many accounts were created.
 */
final class ProvisionDefaultChartAction
{
    public const DEFAULTS = [
        ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'],
        ['code' => '1100', 'name' => 'Accounts receivable', 'type' => 'asset'],
        ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset'],
        ['code' => '2000', 'name' => 'Accounts payable', 'type' => 'liability'],
        ['code' => '2200', 'name' => 'Sales tax payable', 'type' => 'liability'],
        ['code' => '3000', 'name' => "Owner's equity", 'type' => 'equity'],
        ['code' => '4000', 'name' => 'Sales revenue', 'type' => 'revenue'],
        ['code' => '5000', 'name' => 'Cost of goods sold', 'type' => 'expense'],
        ['code' => '6000', 'name' => 'Operating expenses', 'type' => 'expense'],
    ];

    public function execute(User $actor): int
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('accounting.manage');

        return DB::transaction(function () use ($actor, $tenantId): int {
            $have = LedgerAccount::query()->where('tenant_id', $tenantId)->pluck('code')->all();
            $created = 0;

            foreach (self::DEFAULTS as $account) {
                if (in_array($account['code'], $have, true)) {
                    continue;
                }
                app(SaveLedgerAccountAction::class)->execute($actor, null, $account);
                $created++;
            }

            return $created;
        });
    }
}
