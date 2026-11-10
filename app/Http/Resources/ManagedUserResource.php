<?php

namespace App\Http\Resources;

use App\Domain\Users\RoleCatalog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**

 * @mixin User
 */
class ManagedUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = RoleCatalog::primary($this->roles->pluck('name')->all());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role,
            'role_label' => $role === null ? null : RoleCatalog::label($role),
            'active' => $this->deactivated_at === null,
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            'is_self' => (int) $request->user()?->id === (int) $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
