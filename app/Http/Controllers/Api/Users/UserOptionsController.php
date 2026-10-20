<?php

namespace App\Http\Controllers\Api\Users;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;


final class UserOptionsController extends Controller
{
    private const LIMIT = 500;

    public function __invoke(): JsonResponse
    {
        Gate::authorize('accounts.view');


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
