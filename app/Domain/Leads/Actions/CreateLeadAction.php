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

        // The migration's status default('new') is DB-level only: Postgres never
        // reports it back to the in-memory model after an insert, so a status-less
        // $data here would leave $lead->status (and the resource built from it) as
        // null until a re-fetch. Stamp it explicitly so create() is correct in-memory.
        $data['status'] ??= 'new';

        // tenant_id is stamped by BelongsToTenant from TenantContext, never from $data.
        return Lead::create($data);
    }
}