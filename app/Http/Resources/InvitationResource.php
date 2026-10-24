<?php

namespace App\Http\Resources;

use App\Domain\Users\RoleCatalog;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pending invitation as an administrator sees it (8c.2c). An explicit whitelist:
 * the token and its hash never leave through here.
 *
 * @mixin UserInvitation
 */
class InvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'role_label' => RoleCatalog::label($this->role),
            'invited_by_name' => $this->inviter?->name,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expired' => $this->expires_at?->isPast() ?? false,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
