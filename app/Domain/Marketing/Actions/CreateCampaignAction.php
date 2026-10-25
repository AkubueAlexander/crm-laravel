<?php

namespace App\Domain\Marketing\Actions;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class CreateCampaignAction
{
    /** @param array{name: string, subject?: string|null} $data */
    public function execute(User $actor, array $data): Campaign
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('marketing.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        try {
            return DB::transaction(function () use ($actor, $data, $tenantId): Campaign {
                // Tenant, status and creator come from the session, never from the request body (1.3).
                $campaign = new Campaign();
                $campaign->forceFill([
                    'tenant_id' => $tenantId,
                    'name' => $data['name'],
                    'subject' => $data['subject'] ?? null,
                    'status' => Campaign::STATUS_DRAFT,
                    'created_by' => $actor->id,
                ])->save();

                return $campaign;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A campaign with this name already exists.']]);
        }
    }
}
