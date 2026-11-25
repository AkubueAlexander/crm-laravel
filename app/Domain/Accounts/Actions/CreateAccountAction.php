<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Tenant\TenantOwnership;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final class CreateAccountAction
{
    public const KEYS = ['name', 'industry', 'website', 'phone', 'owner_id'];

    /** @param array<string, mixed> $data Format rules live in StoreAccountRequest; invariants are enforced here. */
    public function execute(User $actor, array $data): Account
    {
        Gate::forUser($actor)->authorize('accounts.create');

        // Whitelist: tenant_id and anything else is dropped. tenant_id is stamped by BelongsToTenant.
        $data = Arr::only($data, self::KEYS);

        TenantOwnership::assertUser('owner_id', $data['owner_id'] ?? null, (int) $actor->tenant_id);

        return Account::create($data);
    }
}