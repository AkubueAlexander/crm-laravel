<?php

namespace App\Domain\Users\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class CreateInvitationAction
{
    public const VALID_DAYS = 7;

    /**
     * Creates an invitation, or re-issues the pending one for the same email (new token, new expiry,
     * possibly a new role). Used for "resend" too, so there is exactly one way to mint a token.
     */
    public function execute(User $actor, string $email, string $role, bool $resend = false): UserInvitation
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('users.manage');

        $email = Str::lower(trim($email));

        if (! RoleCatalog::isKnown($role)) {
            throw ValidationException::withMessages(['role' => ['Choose one of the available roles.']]);
        }

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        $token = Str::random(48);

        [$invitation, $tenantName] = DB::transaction(function () use ($actor, $email, $role, $tenantId, $token, $resend): array {
            // Serializes invitations of this tenant, so a double click cannot create two pending rows.
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);

            // Login looks users up by email across ALL tenants, so an email that already has an account
            // anywhere cannot be invited. One generic message: it must not reveal which tenant holds it.
            if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => ["This email address can't be invited."]]);
            }

            $invitation = UserInvitation::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('accepted_at')
                ->whereRaw('lower(email) = ?', [$email])
                ->lockForUpdate()
                ->first() ?? new UserInvitation();

            $existed = $invitation->exists;

            $invitation->forceFill([
                'tenant_id' => $tenantId,
                'email' => $email,
                'role' => $role,
                'token_hash' => UserInvitation::hashToken($token),
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ])->save();

            $event = ! $existed ? 'invitation.created' : ($resend ? 'invitation.resent' : 'invitation.reissued');
            AuditRecorder::record($tenantId, (int) $actor->id, $event, 'invitation', (int) $invitation->id, $email, ['role' => $role]);

            return [$invitation, $tenant->name];
        });

        // After commit: a rolled-back invitation never sends mail.
        Notification::route('mail', $email)->notify(new UserInvitationNotification(
            token: $token,
            tenantName: $tenantName,
            roleLabel: RoleCatalog::label($role),
            inviterName: $actor->name,
            expiresAtIso: $invitation->expires_at->toIso8601String(),
        ));

        return $invitation;
    }
}
