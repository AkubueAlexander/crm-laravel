<?php

namespace App\Domain\Marketing\Actions;

use App\Models\Automation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class DeleteAutomationAction
{
    public function execute(User $actor, int $automationId): void
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        Gate::forUser($actor)->authorize('marketing.manage');

        DB::transaction(function () use ($automationId, $tenantId): void {
            $automation = Automation::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($automationId);

            // An active automation may have contacts mid-sequence: it must be paused first.
            if ($automation->status === Automation::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'automation' => ['Pause this automation before deleting it.'],
                ]);
            }

            // automation_steps rows go with it (FK cascade).
            $automation->delete();
        });
    }
}