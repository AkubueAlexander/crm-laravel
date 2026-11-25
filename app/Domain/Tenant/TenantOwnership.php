<?php

namespace App\Domain\Tenant;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invariant checks Actions run regardless of caller: a referenced record must belong to the
 * actor's tenant. Another tenant's id is reported exactly like a missing one.
 */
final class TenantOwnership
{
    public static function assertUser(string $field, mixed $id, int $tenantId): void
    {
        if ($id === null || $id === '') {
            return;
        }

        $exists = is_numeric($id) && DB::table('users')->where('tenant_id', $tenantId)->where('id', (int) $id)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => ['The selected user does not exist.']]);
        }
    }

    public static function assertAccount(string $field, mixed $id, int $tenantId): void
    {
        if ($id === null || $id === '') {
            return;
        }

        $exists = is_numeric($id) && DB::table('accounts')
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => ['The selected account does not exist.']]);
        }
    }
}