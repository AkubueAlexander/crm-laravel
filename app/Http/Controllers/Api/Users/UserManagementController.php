<?php

namespace App\Http\Controllers\Api\Users;

use App\Domain\Users\Actions\ChangeUserRoleAction;
use App\Domain\Users\Actions\SetUserActiveStateAction;
use App\Domain\Users\RoleCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ChangeUserRoleRequest;
use App\Http\Requests\Users\ListUsersRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class UserManagementController extends Controller
{
    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $q = trim((string) ($v['q'] ?? ''));

        // User has no tenant scope: this predicate IS the isolation (1.3).
        $users = User::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->with('roles')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
            })
            ->when(($v['status'] ?? null) === 'active', fn ($query) => $query->whereNull('deactivated_at'))
            ->when(($v['status'] ?? null) === 'deactivated', fn ($query) => $query->whereNotNull('deactivated_at'))
            ->when($v['role'] ?? null, fn ($query, $role) => $query->whereHas('roles', fn ($r) => $r->where('roles.name', $role)))
            ->orderBy($v['sort'] ?? 'name', $v['direction'] ?? 'asc')
            ->orderBy('id') // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return ManagedUserResource::collection($users);
    }

    public function roles(): JsonResponse
    {
        Gate::authorize('users.manage');

        return response()->json(['data' => RoleCatalog::describe()]);
    }

    public function changeRole(ChangeUserRoleRequest $request, int $user, ChangeUserRoleAction $action): ManagedUserResource
    {
        return new ManagedUserResource($action->execute($request->user(), $user, $request->validated('role')));
    }

    public function deactivate(Request $request, int $user, SetUserActiveStateAction $action): ManagedUserResource
    {
        return new ManagedUserResource($action->execute($request->user(), $user, false));
    }

    public function reactivate(Request $request, int $user, SetUserActiveStateAction $action): ManagedUserResource
    {
        return new ManagedUserResource($action->execute($request->user(), $user, true));
    }
}
