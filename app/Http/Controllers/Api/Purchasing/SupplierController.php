<?php

namespace App\Http\Controllers\Api\Purchasing;

use App\Domain\Purchasing\Actions\SaveSupplierAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// {supplier} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class SupplierController extends Controller
{
    private const SORT_COLUMNS = ['name' => 'name', 'created_at' => 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('inventory.view');

        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));
        $direction = $v['direction'] ?? 'asc';
        $column = self::SORT_COLUMNS[$v['sort'] ?? 'name'];

        $rows = Supplier::query()
            ->where('tenant_id', $tenantId)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like);
                });
            })
            ->when(isset($v['active']), fn ($query) => $query->where('is_active', filter_var($v['active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction) // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return SupplierResource::collection($rows);
    }

    public function show(Request $request, int $supplier): SupplierResource
    {
        Gate::authorize('inventory.view');

        return new SupplierResource(
            Supplier::query()->where('tenant_id', (int) $request->user()->tenant_id)->findOrFail($supplier),
        );
    }

    public function store(Request $request, SaveSupplierAction $action): JsonResponse
    {
        Gate::authorize('inventory.manage');

        $supplier = $action->execute($request->user(), null, $this->validated($request));

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $supplier, SaveSupplierAction $action): SupplierResource
    {
        Gate::authorize('inventory.manage');

        return new SupplierResource($action->execute($request->user(), $supplier, $this->validated($request)));
    }

    /** Shape check only. SaveSupplierAction re-checks permission, formats and name uniqueness (2.4). @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
