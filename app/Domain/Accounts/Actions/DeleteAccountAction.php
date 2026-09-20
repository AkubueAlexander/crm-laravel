<?php

namespace App\Domain\Accounts\Actions;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteAccountAction
{
    public function execute(User $actor, Account $account): void
    {
        Gate::forUser($actor)->authorize('accounts.delete');

        $account->delete();
    }
}