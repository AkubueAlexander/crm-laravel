<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Domain\Leads\Forms\DeleteLeadFormAction;
use App\Domain\Leads\Forms\LeadFormDsl;
use App\Domain\Leads\Forms\SaveLeadFormAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadFormResource;
use App\Models\LeadForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * 9.8a. {leadForm} is a plain int id (no route-model binding); every lookup carries an explicit tenant predicate.
 * The Actions validate and authorize again (2.4); there is deliberately no FormRequest that could be bypassed.
 */
class LeadFormController extends Controller
{
    private const KEYS = ['name', 'is_active', 'source', 'success_message', 'fields'];

    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        Gate::authorize('marketing.view');

        return LeadFormResource::collection(
            LeadForm::query()
                ->where('tenant_id', (int) $request->user()->tenant_id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
        );
    }

    /** GET /api/v1/lead-forms/catalog: the whitelist the builder renders (never hardcoded in the SPA). */
    public function catalog()
    {
        Gate::authorize('marketing.view');

        return response()->json([
            'data' => LeadFormDsl::catalog(),
            'max_fields' => count(LeadFormDsl::FIELDS),
        ]);
    }

    public function show(Request $request, int $leadForm)
    {
        Gate::authorize('marketing.view');

        return new LeadFormResource($this->find($request, $leadForm));
    }

    public function store(Request $request, SaveLeadFormAction $action)
    {
        $created = $action->create($request->user(), $request->only(self::KEYS));

        return (new LeadFormResource($created))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $leadForm, SaveLeadFormAction $action)
    {
        return new LeadFormResource($action->update($request->user(), $leadForm, $request->only(self::KEYS)));
    }

    public function destroy(Request $request, int $leadForm, DeleteLeadFormAction $action)
    {
        $action->execute($request->user(), $leadForm);

        return response()->noContent();
    }

    private function find(Request $request, int $id): LeadForm
    {
        return LeadForm::query()->where('tenant_id', (int) $request->user()->tenant_id)->findOrFail($id);
    }
}