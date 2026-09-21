<?php

namespace App\Domain\Leads\Actions;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteLeadAction
{
    public function execute(User $actor, Lead $lead): void
    {
        Gate::forUser($actor)->authorize('leads.delete');

        $lead->delete();
    }
}