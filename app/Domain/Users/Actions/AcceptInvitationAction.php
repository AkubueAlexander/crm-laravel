<?php

namespace App\Domain\Users\Actions;

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\Exceptions\UserRuleViolation;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * 8c.2c: the invitee's side. There is no authenticated actor: the emailed token IS the credential,
 * so everything the account gets (tenant, role, email) comes from the invitation row, never the request.
 */
final class AcceptInvitationAction
{
    /** Read-only view for the accept page. Throws the same 410 for every kind of unusable link. */
    public function preview(string $token): UserInvitation
    {
        $invitation = $this->find($token);
        $this->ensureUsable($invitation);

        return $invitation->load('tenant:id,name');
    }

    /** Creates the user, assigns the role and burns the invitation, atomically. */
    public function execute(string $token, string $name, string $password): User
    {
        try {
            return DB::transaction(function () use ($token, $name, $password): User {
                // Row lock: two simultaneous accepts of one link are serialized, the second sees it used.
                $invitation = $this->find($token, forUpdate: true);
                $this->ensureUsable($invitation);

                $email = Str::lower($invitation->email);

                // Login is by email across all tenants, so no two accounts may share one. Serialize accepts of
                // the same email (even from different tenants' invitations) before checking for an account.
                DB::select('select pg_advisory_xact_lock(hashtext(?))', [$email]);
                if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                    throw UserRuleViolation::invitationInvalid();
                }

                $tenant = Tenant::query()->findOrFail($invitation->tenant_id);
                $tenantId = (int) $tenant->id;

                TenantContext::set($tenant);
                app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
                RoleCatalog::ensureRoles($tenantId);

                $user = new User();
                $user->forceFill([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'email' => $email,
                    'password' => $password, // hashed by the model cast
                    // Receiving the emailed link proves they own the address.
                    'email_verified_at' => now(),
                ])->save();

                $user->syncRoles([$invitation->role]);

                $invitation->forceFill(['accepted_at' => now()])->save();

                return $user;
            });
        } finally {
            TenantContext::clear();
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }

    private function find(string $token, bool $forUpdate = false): UserInvitation
    {
        $query = UserInvitation::query()->where('token_hash', UserInvitation::hashToken($token));

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw UserRuleViolation::invitationInvalid();
    }

    private function ensureUsable(UserInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null || $invitation->isExpired() || ! RoleCatalog::isKnown($invitation->role)) {
            throw UserRuleViolation::invitationInvalid();
        }

        // Also checked (under a lock) inside execute(); here it keeps the preview honest.
        if (User::query()->whereRaw('lower(email) = ?', [Str::lower($invitation->email)])->exists()) {
            throw UserRuleViolation::invitationInvalid();
        }
    }
}
