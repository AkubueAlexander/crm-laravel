<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Domain\Accounts\Actions\CreateAccountAction;
use App\Domain\Accounts\Actions\DeleteAccountAction;
use App\Domain\Accounts\Actions\UpdateAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\ListAccountsRequest;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class AccountController extends Controller
{
    public function index(ListAccountsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $q = trim($v['q'] ?? '');

        $accounts = Account::query()
            ->with('owner:id,name')
            ->withCount('contacts')
            ->when($q !== '', function ($query) use ($q) {
                // Escape LIKE wildcards; Postgres uses backslash as the default escape.
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where('name', 'ilike', $like);
            })
            ->orderBy($v['sort'] ?? 'name', $v['direction'] ?? 'asc')
            ->orderBy('id') // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return AccountResource::collection($accounts);
    }

    public function show(Account $account): AccountResource
    {
        Gate::authorize('accounts.view');

        return $this->present($account);
    }

    public function store(StoreAccountRequest $request, CreateAccountAction $action): AccountResource
    {
        // A freshly created model makes Laravel respond 201 automatically.
        return $this->present($action->execute($request->user(), $request->validated()));
    }

    public function update(UpdateAccountRequest $request, Account $account, UpdateAccountAction $action): AccountResource
    {
        return $this->present($action->execute($request->user(), $account, $request->validated()));
    }

    public function destroy(Request $request, Account $account, DeleteAccountAction $action): Response
    {
        $action->execute($request->user(), $account);

        return response()->noContent();
    }

    /** Same response shape from every endpoint (owner + contacts_count always present). */
    private function present(Account $account): AccountResource
    {
        return new AccountResource($account->load('owner:id,name')->loadCount('contacts'));
    }
}