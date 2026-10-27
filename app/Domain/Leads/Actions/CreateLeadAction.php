<?php

namespace App\Domain\Leads\Actions;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CreateLeadAction
{
    /** @param array<string, mixed> $data Already validated by StoreLeadRequest. */
    public function execute(User $actor, array $data): Lead
    {
        Gate::forUser($actor)->authorize('leads.create');


        $data['status'] ??= 'new';


        $lead = Lead::create($data);

        // 9.6c-1: let active "lead_created" automations enroll the new lead (queued after commit).
        \App\Jobs\EnrollForTriggerJob::fire((int) $lead->tenant_id, 'lead_created', null, (int) $lead->id);

        // 9.7: score the new lead (stored on the row, also set on the returned model).
        \App\Domain\Leads\Scoring\LeadScoring::applyTo($lead);

        return $lead;
    }
}
