<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Authorizes `tenant.{tenantId}.campaigns` (9.4d).
 *
 * Runs at socket-subscribe time, the one auth path that skips the normal request middleware (1.5),
 * so everything is re-verified here against the database: same tenant, account still active,
 * and `marketing.view` under that tenant's permission team.
 */
final class CampaignsChannel
{
    public function join(User $user, int|string $tenantId): bool
    {
        $tenantId = (int) $tenantId;

        if ((int) $user->tenant_id !== $tenantId) {
            return false;
        }

        $fresh = DB::table('users')
            ->where('id', $user->getKey())
            ->where('tenant_id', $tenantId)
            ->first();

        if ($fresh === null) {
            return false;
        }

        foreach (['deactivated_at', 'disabled_at'] as $column) {
            if (property_exists($fresh, $column) && $fresh->{$column} !== null) {
                return false;
            }
        }
        foreach (['is_active', 'active'] as $column) {
            if (property_exists($fresh, $column) && ! $fresh->{$column}) {
                return false;
            }
        }

        setPermissionsTeamId($tenantId);

        // A fresh instance so no stale cached roles/permissions relation is consulted.
        $live = User::query()->whereKey($user->getKey())->where('tenant_id', $tenantId)->first();

        return $live !== null && $live->can('marketing.view');
    }
}