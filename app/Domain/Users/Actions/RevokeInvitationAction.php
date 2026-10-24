<?php

namespace App\Domain\Users\Actions;

use App\Domain\Audit\AuditRecorder;
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

        DB::transaction(function () use ($actor, $tenantId, $invitationId): void {
            $invitation = UserInvitation::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->findOrFail($invitationId);

            $invitation->delete();

            // 8c.3: the label keeps the address readable after the row is gone.
            AuditRecorder::record($tenantId, (int) $actor->id, 'invitation.revoked', 'invitation', $invitationId, $invitation->email, ['role' => $invitation->role]);
        });
    }
}
