<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An employee as the SPA sees it (13.1). Explicit whitelist: no tenant id.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'hire_date' => $this->hire_date?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'team' => $this->team ? ['id' => $this->team->id, 'name' => $this->team->name] : null,
            'territory' => $this->territory ? ['id' => $this->territory->id, 'name' => $this->territory->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
