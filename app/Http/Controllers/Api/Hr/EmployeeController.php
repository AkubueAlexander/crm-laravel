<?php

namespace App\Http\Controllers\Api\Hr;

use App\Domain\Hr\Actions\SaveEmployeeAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// 13.1b: employees. {employee} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class EmployeeController extends Controller
{
    private const SORT_COLUMNS = ['name' => 'name', 'hire_date' => 'hire_date', 'created_at' => 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('hr.view');

        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'territory_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));
        $direction = $v['direction'] ?? 'asc';
        $column = self::SORT_COLUMNS[$v['sort'] ?? 'name'];

        $rows = Employee::query()
            ->where('tenant_id', $tenantId)
            ->with(['team', 'territory', 'user'])
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('job_title', 'ilike', $like);
                });
            })
            ->when(isset($v['active']), fn ($query) => $query->where('is_active', filter_var($v['active'], FILTER_VALIDATE_BOOLEAN)))
            ->when(isset($v['team_id']), fn ($query) => $query->where('team_id', (int) $v['team_id']))
            ->when(isset($v['territory_id']), fn ($query) => $query->where('territory_id', (int) $v['territory_id']))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction) // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return EmployeeResource::collection($rows);
    }

    public function show(Request $request, int $employee): EmployeeResource
    {
        Gate::authorize('hr.view');

        return new EmployeeResource(
            Employee::query()->where('tenant_id', (int) $request->user()->tenant_id)->with(['team', 'territory', 'user'])->findOrFail($employee),
        );
    }

    public function store(Request $request, SaveEmployeeAction $action): JsonResponse
    {
        Gate::authorize('hr.manage');

        $employee = $action->execute($request->user(), null, $this->validated($request));

        return (new EmployeeResource($employee->load(['team', 'territory', 'user'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $employee, SaveEmployeeAction $action): EmployeeResource
    {
        Gate::authorize('hr.manage');

        return new EmployeeResource($action->execute($request->user(), $employee, $this->validated($request))->load(['team', 'territory', 'user']));
    }

    /** Shape check only. SaveEmployeeAction re-checks permission, formats, ownership of the linked ids and uniqueness (2.4). @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hire_date' => ['nullable', 'string', 'max:10'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'territory_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
