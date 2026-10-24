<?php

namespace App\Domain\Users\Actions;

use App\Domain\Users\Exceptions\UserRuleViolation;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class ChangeUserRoleAction
{
    /** A user holds exactly one role: the target's roles are replaced by $role. */
    public function execute(User $actor, int $targetId, string $role): User
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('users.manage');

        if (! RoleCatalog::isKnown($role)) {
            throw ValidationException::withMessages(['role' => ['Choose one of the available roles.']]);
        }

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        return DB::transaction(function () use ($actor, $targetId, $role, $tenantId): User {
            // Serializes every admin-affecting change of this tenant, so two racing demotions
            // cannot together remove the last administrator.
            Tenant::query()->lockForUpdate()->findOrFail($tenantId);

            // User has no tenant scope: the tenant predicate here IS the isolation (1.3).
            $target = User::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($targetId);

            if ((int) $target->id === (int) $actor->id) {
                throw UserRuleViolation::selfModification();
            }

            $targetIsAdmin = $target->roles()->where('roles.name', RoleCatalog::ADMIN)->exists();

            if ($targetIsAdmin
                && $role !== RoleCatalog::ADMIN
                && $target->deactivated_at === null
                && RoleCatalog::activeAdminCount($tenantId) <= 1) {
                throw UserRuleViolation::lastAdmin();
            }

            RoleCatalog::ensureRoles($tenantId);
            $target->syncRoles([$role]);

            return $target;
        });
    }
}
