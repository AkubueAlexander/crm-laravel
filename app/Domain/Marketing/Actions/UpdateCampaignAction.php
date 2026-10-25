<?php

namespace App\Domain\Marketing\Actions;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class UpdateCampaignAction
{
    /** @param array{name: string, subject?: string|null} $data */
    public function execute(User $actor, int $campaignId, array $data): Campaign
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('marketing.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        try {
            return DB::transaction(function () use ($campaignId, $data, $tenantId): Campaign {
                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $campaign = Campaign::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($campaignId);

                if (! $campaign->isDraft()) {
                    throw ValidationException::withMessages(['status' => ['Only draft campaigns can be edited.']]);
                }

                $campaign->forceFill([
                    'name' => $data['name'],
                    'subject' => $data['subject'] ?? null,
                ])->save();

                return $campaign;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A campaign with this name already exists.']]);
        }
    }
}
