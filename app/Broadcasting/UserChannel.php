<?php

namespace App\Broadcasting;

use App\Models\User;

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
