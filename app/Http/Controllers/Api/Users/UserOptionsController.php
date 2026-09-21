<?php

namespace App\Http\Controllers\Api\Users;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only {id, name} source for owner selects (accounts now; deals/leads later).
 *
 * User opts out of the BelongsToTenant query scope ($skipTenantQueryScope, required so
 * login can find users before ResolveTenant runs). The explicit tenant_id filter below
 * is therefore the ONLY tenant boundary on this query. Do not remove it.
 */
final class UserOptionsController extends Controller
{
    private const LIMIT = 500;

    public function __invoke(): JsonResponse
    {
        Gate::authorize('accounts.view');

        // Fail closed: where('tenant_id', null) would match tenant-less rows.
        abort_unless(TenantContext::hasTenant(), 403);

        $users = User::query()
            ->where('tenant_id', TenantContext::id())
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'name']);

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])->all(),
        ]);
    }
}