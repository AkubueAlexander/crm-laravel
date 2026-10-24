<?php

namespace App\Domain\Users\Actions;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final class RevokeInvitationAction
{
    /** Deletes a still-pending invitation of the actor's tenant, which kills its link. 404 otherwise. */
    public function execute(User $actor, int $invitationId): void
    {
        Gate::forUser($actor)->authorize('users.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        DB::transaction(function () use ($tenantId, $invitationId): void {
            UserInvitation::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->findOrFail($invitationId)
                ->delete();
        });
    }
}
