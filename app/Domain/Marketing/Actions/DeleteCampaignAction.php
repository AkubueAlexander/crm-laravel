<?php

namespace App\Domain\Marketing\Actions;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class DeleteCampaignAction
{
    public function execute(User $actor, int $campaignId): void
    {
        Gate::forUser($actor)->authorize('marketing.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        DB::transaction(function () use ($campaignId, $tenantId): void {
            $campaign = Campaign::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($campaignId);

            // A campaign that has been scheduled or sent is history: it is never deleted.
            if (! $campaign->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Only draft campaigns can be deleted.']]);
            }

            $campaign->delete();
        });
    }
}
