<?php

namespace App\Domain\Users\Actions;

use App\Domain\Audit\AuditRecorder;
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

        Gate::forUser($actor)->authorize('users.manage');

        if (! RoleCatalog::isKnown($role)) {
            throw ValidationException::withMessages(['role' => ['Choose one of the available roles.']]);
        }

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        return DB::transaction(function () use ($actor, $targetId, $role, $tenantId): User {

            Tenant::query()->lockForUpdate()->findOrFail($tenantId);


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
            $from = RoleCatalog::primary($target->roles()->pluck('roles.name')->all());
            $target->syncRoles([$role]);


            if ($from !== $role) {
                AuditRecorder::record($tenantId, (int) $actor->id, 'user.role_changed', 'user', (int) $target->id, $target->name, ['from' => $from, 'to' => $role]);
            }

            return $target;
        });
    }
}
