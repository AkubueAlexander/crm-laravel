<?php

namespace App\Http\Controllers\Api\Hr;

use App\Domain\Hr\Actions\SaveHrUnitAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\HrUnitResource;
use App\Models\HrUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// 13.1a: teams and territories. The kind comes from the route default (routes/hr.php) and is read from the route, not
// taken as an argument: Laravel passes plain route parameters positionally, so a default after {unit} would swap them.
// {unit} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate and the kind.
final class HrUnitController extends Controller
{
    private const SORT_COLUMNS = ['name' => 'name', 'created_at' => 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $kind = $this->kind($request);
        Gate::authorize('hr.view');

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

        $rows = HrUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', $kind)
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '\\%_').'%'))
            ->when(isset($v['active']), fn ($query) => $query->where('is_active', filter_var($v['active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction) // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return HrUnitResource::collection($rows);
    }

    public function show(Request $request, int $unit): HrUnitResource
    {
        $kind = $this->kind($request);
        Gate::authorize('hr.view');

        return new HrUnitResource(
            HrUnit::query()->where('tenant_id', (int) $request->user()->tenant_id)->where('kind', $kind)->findOrFail($unit),
        );
    }

    public function store(Request $request, SaveHrUnitAction $action): JsonResponse
    {
        $kind = $this->kind($request);
        Gate::authorize('hr.manage');

        $unit = $action->execute($request->user(), $kind, null, $this->validated($request));

        return (new HrUnitResource($unit))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $unit, SaveHrUnitAction $action): HrUnitResource
    {
        $kind = $this->kind($request);
        Gate::authorize('hr.manage');

        return new HrUnitResource($action->execute($request->user(), $kind, $unit, $this->validated($request)));
    }

    private function kind(Request $request): string
    {
        $kind = (string) $request->route('kind');
        abort_unless(in_array($kind, HrUnit::KINDS, true), 404);

        return $kind;
    }

    /** Shape check only. SaveHrUnitAction re-checks permission, lengths and name uniqueness (2.4). @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
