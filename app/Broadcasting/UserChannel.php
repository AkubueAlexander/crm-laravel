<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorizes private-tenant.{tenantId}.user.{userId} (1.5). Runs at socket-subscribe
 * time, outside the normal request middleware stack, so it re-verifies everything:
 * the channel's user is the caller, the channel's tenant is the caller's tenant, and
 * the membership still holds in the database right now.
 */
class UserChannel
{
    public function join(User $user, int|string $tenantId, int|string $userId): bool
    {
        if ($user->tenant_id === null) {
            return false;
        }

        if ((int) $user->getKey() !== (int) $userId) {
            return false;
        }

        if ((int) $user->tenant_id !== (int) $tenantId) {
            return false;
        }

        return User::query()
            ->whereKey($user->getKey())
            ->where('tenant_id', (int) $tenantId)
            ->whereNull('deactivated_at')
            ->exists();
    }
}
