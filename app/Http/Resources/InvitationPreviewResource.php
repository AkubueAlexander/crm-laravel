<?php

namespace App\Http\Resources;

use App\Domain\Users\RoleCatalog;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the public accept page may show to whoever holds the link (8c.2c). Even smaller than the admin
 * view: no ids, no inviter, never the token or its hash.
 *
 * @mixin UserInvitation
 */
class InvitationPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'email' => $this->email,
            'tenant_name' => $this->tenant?->name,
            'role' => $this->role,
            'role_label' => RoleCatalog::label($this->role),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
