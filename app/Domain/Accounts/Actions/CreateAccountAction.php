<?php

namespace App\Domain\Accounts\Actions;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CreateAccountAction
{
    /** @param array<string, mixed> $data Already validated by StoreAccountRequest. */
    public function execute(User $actor, array $data): Account
    {
        Gate::forUser($actor)->authorize('accounts.create');

        // tenant_id is stamped by BelongsToTenant from TenantContext, never from $data.
        return Account::create($data);
    }
}