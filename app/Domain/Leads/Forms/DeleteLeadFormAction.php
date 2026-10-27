<?php

namespace App\Domain\Leads\Forms;

use App\Models\LeadForm;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/** 9.8a: deleting a form never touches the leads it captured (they keep their source text). */
final class DeleteLeadFormAction
{
    public function execute(User $actor, int $formId): void
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        Gate::forUser($actor)->authorize('marketing.manage');

        LeadForm::query()->where('tenant_id', $tenantId)->findOrFail($formId)->delete();
    }
}