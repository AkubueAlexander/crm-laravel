<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Support\Facades\DB;


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


        $live = User::query()->whereKey($user->getKey())->where('tenant_id', $tenantId)->first();

        return $live !== null && $live->can('marketing.view');
    }
}
