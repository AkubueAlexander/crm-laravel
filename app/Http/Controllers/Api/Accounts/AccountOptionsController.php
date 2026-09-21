<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only {id, name} source for account pickers (contact form now; deals/leads later).
 *
 * Account uses BelongsToTenant, so its fail-closed global scope is the tenant boundary
 * here, and SoftDeletes keeps deleted accounts out of the picker.
 */
final class AccountOptionsController extends Controller
{
    private const LIMIT = 500;

    public function __invoke(): JsonResponse
    {
        Gate::authorize('accounts.view');

        $accounts = Account::query()
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'name']);

        return response()->json([
            'data' => $accounts->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
            ])->all(),
        ]);
    }
}