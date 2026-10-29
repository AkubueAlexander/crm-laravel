<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Domain\Accounting\Actions\ProvisionDefaultChartAction;
use App\Domain\Accounting\Actions\SaveLedgerAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerAccountResource;
use App\Models\LedgerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// {account} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class LedgerAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('accounting.view');

        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(LedgerAccount::TYPES)],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($v['q'] ?? ''));

        $rows = LedgerAccount::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like);
                });
            })
            ->when($v['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when(isset($v['active']), fn ($query) => $query->where('is_active', filter_var($v['active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('code')
            ->orderBy('id')
            ->paginate($v['per_page'] ?? 100)
            ->withQueryString();

        return LedgerAccountResource::collection($rows);
    }

    public function store(Request $request, SaveLedgerAccountAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        $account = $action->execute($request->user(), null, $this->validated($request));

        return (new LedgerAccountResource($account))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $account, SaveLedgerAccountAction $action): LedgerAccountResource
    {
        Gate::authorize('accounting.manage');

        return new LedgerAccountResource($action->execute($request->user(), $account, $this->validated($request)));
    }

    /** Loads the starter chart of accounts. Idempotent: returns how many accounts were created. */
    public function defaults(Request $request, ProvisionDefaultChartAction $action): JsonResponse
    {
        return response()->json(['created' => $action->execute($request->user())]);
    }

    /** Shape check only. SaveLedgerAccountAction re-checks permission, formats, uniqueness and frozen fields (2.4). @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', Rule::in(LedgerAccount::TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
