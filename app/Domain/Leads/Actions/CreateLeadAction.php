<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\LeadRules;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class CreateLeadAction
{
    /** @param array<string, mixed> $data see LeadRules::store() for the accepted shape */
    public function execute(User $actor, array $data): Lead
    {
        Gate::forUser($actor)->authorize('leads.create');

        // Re-validate here: the Form Request is UX, this is the boundary (2.4). Only validated keys survive,
        // so tenant_id, converted_* and friends can never be mass-assigned through this Action.
        $data = Validator::make($data, LeadRules::store((int) $actor->tenant_id))->validate();

        $data['status'] ??= 'new';

        $lead = Lead::create($data);

        // 9.6c-1: let active "lead_created" automations enroll the new lead (queued after commit).
        \App\Jobs\EnrollForTriggerJob::fire((int) $lead->tenant_id, 'lead_created', null, (int) $lead->id);

        // 9.7: score the new lead (stored on the row, also set on the returned model).
        \App\Domain\Leads\Scoring\LeadScoring::applyTo($lead);

        return $lead;
    }
}