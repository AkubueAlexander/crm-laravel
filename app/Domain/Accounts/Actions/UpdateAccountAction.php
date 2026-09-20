<?php

namespace App\Domain\Accounts\Actions;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateAccountAction
{
    /** @param array<string, mixed> $data Already validated by UpdateAccountRequest. */
    public function execute(User $actor, Account $account, array $data): Account
    {
        Gate::forUser($actor)->authorize('accounts.update');

        $account->update($data);

        return $account;
    }
}