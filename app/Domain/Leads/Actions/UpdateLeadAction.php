<?php

namespace App\Domain\Leads\Actions;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateLeadAction
{
    /** @param array<string, mixed> $data Already validated by UpdateLeadRequest. */
    public function execute(User $actor, Lead $lead, array $data): Lead
    {
        Gate::forUser($actor)->authorize('leads.update');

        // "status" can never reach "converted" here: UpdateLeadRequest's
        // ASSIGNABLE_STATUSES excludes it. Only ConvertLeadAction sets it.
        $lead->update($data);

        return $lead;
    }
}