<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Tenant\TenantOwnership;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final class UpdateAccountAction
{
    /** @param array<string, mixed> $data Format rules live in UpdateAccountRequest; invariants are enforced here. */
    public function execute(User $actor, Account $account, array $data): Account
    {
        Gate::forUser($actor)->authorize('accounts.update');

        $data = Arr::only($data, CreateAccountAction::KEYS);

        TenantOwnership::assertUser('owner_id', $data['owner_id'] ?? null, (int) $actor->tenant_id);

        $account->update($data);

        return $account;
    }
}