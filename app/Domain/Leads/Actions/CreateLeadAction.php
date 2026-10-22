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


        return Lead::create($data);
    }
}
