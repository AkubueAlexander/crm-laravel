<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\LeadRules;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class UpdateLeadAction
{
    /** @param array<string, mixed> $data see LeadRules::update() for the accepted shape */
    public function execute(User $actor, Lead $lead, array $data): Lead
    {
        Gate::forUser($actor)->authorize('leads.update');

        // "status" can never reach "converted" here: LeadRules excludes it, and this Action
        // enforces that itself. Only ConvertLeadAction sets it.
        $data = Validator::make($data, LeadRules::update((int) $actor->tenant_id))->validate();

        $lead->update($data);

        // 9.7: any field the score depends on may have changed.
        \App\Domain\Leads\Scoring\LeadScoring::applyTo($lead);

        return $lead;
    }
}