<?php

namespace App\Domain\Users\Actions;

use App\Domain\Users\Exceptions\UserRuleViolation;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

final class SetUserActiveStateAction
{
    public function execute(User $actor, int $targetId, bool $active): User
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('users.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        return DB::transaction(function () use ($actor, $targetId, $active, $tenantId): User {
            Tenant::query()->lockForUpdate()->findOrFail($tenantId);

            // User has no tenant scope: the tenant predicate here IS the isolation (1.3).
            $target = User::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($targetId);

            if ($active) {
                if ($target->deactivated_at !== null) {
                    $target->forceFill(['deactivated_at' => null])->save();
                }

                return $target;
            }

            if ((int) $target->id === (int) $actor->id) {
                throw UserRuleViolation::selfModification();
            }

            // Idempotent: deactivating an already deactivated account changes nothing.
            if ($target->deactivated_at !== null) {
                return $target;
            }

            $targetIsAdmin = $target->roles()->where('roles.name', RoleCatalog::ADMIN)->exists();

            if ($targetIsAdmin && RoleCatalog::activeAdminCount($tenantId) <= 1) {
                throw UserRuleViolation::lastAdmin();
            }

            $target->forceFill([
                'deactivated_at' => now(),
                // Invalidates any "remember me" cookie the account still holds.
                'remember_token' => Str::random(60),
            ])->save();

            $this->revokeSessions((int) $target->id);

            return $target;
        });
    }

    /**
     * Open sessions are also refused on their next request (ResolveTenant), so this is belt and
     * braces for the database session driver: the rows are simply gone.
     */
    private function revokeSessions(int $userId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->delete();
    }
}
