<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'two_factor_enabled' => ! is_null($this->two_factor_confirmed_at),
            'tenant' => $this->whenLoaded('tenant', fn () => new TenantResource($this->tenant)),
            // Spatie teams-mode, scoped to the user's tenant (team_id = tenant_id).
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
        ];
    }
}
