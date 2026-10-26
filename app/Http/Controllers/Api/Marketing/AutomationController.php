<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Domain\Marketing\Actions\DeleteAutomationAction;
use App\Domain\Marketing\Actions\SaveAutomationAction;
use App\Domain\Marketing\Actions\TransitionAutomationAction;
use App\Domain\Marketing\Automations\AutomationDsl;
use App\Domain\Marketing\Exceptions\AutomationTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\SaveAutomationRequest;
use App\Http\Requests\Marketing\TransitionAutomationRequest;
use App\Http\Resources\AutomationResource;
use App\Models\Automation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** 9.6a-2. {automation} is a plain int id (no route-model binding); every lookup carries an explicit tenant predicate. */
class AutomationController extends Controller
{
    private const SORTS = ['name', 'status', 'created_at', 'updated_at'];

    private const PER_PAGE = 25;

    /** GET /api/v1/automations?page=&sort=&direction=&q= */
    public function index(Request $request)
    {
        Gate::authorize('marketing.view');

        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $q = trim((string) $request->query('q', ''));

        $query = Automation::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->with('steps');

        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw("lower(coalesce(description, '')) like ?", [$like]));
        }

        return AutomationResource::collection(
            $this->withEnrollmentCounts($query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate(self::PER_PAGE)->withQueryString(), (int) $request->user()->tenant_id)
        );
    }

    /** GET /api/v1/automations/catalog: what the builder renders (never hardcoded in the SPA). */
    public function catalog()
    {
        Gate::authorize('marketing.view');

        return response()->json(['data' => AutomationDsl::catalog()]);
    }

    public function show(Request $request, int $automation)
    {
        Gate::authorize('marketing.view');

        return new AutomationResource($this->find($request, $automation));
    }

    public function store(SaveAutomationRequest $request, SaveAutomationAction $action)
    {
        $created = $action->create($request->user(), $request->validated());

        return (new AutomationResource($created))->response()->setStatusCode(201);
    }

    public function update(SaveAutomationRequest $request, int $automation, SaveAutomationAction $action)
    {
        return new AutomationResource($action->update($request->user(), $automation, $request->validated()));
    }

    public function destroy(Request $request, int $automation, DeleteAutomationAction $action)
    {
        $action->execute($request->user(), $automation);

        return response()->noContent();
    }

    /** POST /api/v1/automations/{automation}/transition  { to_status, lock_version } */
    public function transition(TransitionAutomationRequest $request, int $automation, TransitionAutomationAction $action)
    {
        try {
            $updated = $action->execute(
                $request->user(),
                $automation,
                $request->validated('to_status'),
                (int) $request->validated('lock_version'),
            );
        } catch (AutomationTransitionException $e) {
            if ($e->isStale() && $e->automation !== null) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => [],
                    'code' => $e->reason,
                    'current' => new AutomationResource($e->automation),
                ], 409);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
                'code' => $e->reason,
            ], 422);
        }

        return new AutomationResource($updated);
    }

    private function withEnrollmentCounts(\Illuminate\Pagination\LengthAwarePaginator $page, int $tenantId): \Illuminate\Pagination\LengthAwarePaginator
    {
        // 9.6c-3: one grouped query for the whole page instead of one per row.
        $counts = \App\Domain\Marketing\Automations\EnrollmentCounts::forAutomations($tenantId, $page->getCollection()->pluck('id')->all());
        $page->getCollection()->each(fn (Automation $a) => $a->setAttribute('enrollment_counts', $counts[(int) $a->id]));

        return $page;
    }

    private function find(Request $request, int $id): Automation
    {
        return Automation::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->with('steps')
            ->findOrFail($id);
    }
}