<?php

namespace App\Http\Controllers\Api\Leads;

use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\DeleteLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\Exceptions\LeadNotConvertibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\ConvertLeadRequest;
use App\Http\Requests\Leads\ListLeadsRequest;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class LeadController extends Controller
{
    public function index(ListLeadsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $q = trim($v['q'] ?? '');

        $leads = Lead::query()
            ->with('owner:id,name')
            ->when($q !== '', function ($query) use ($q) {

                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhere('company', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like);
                });
            })
            ->when($v['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($v['sort'] ?? 'created_at', $v['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return LeadResource::collection($leads);
    }

    public function show(Lead $lead): LeadResource
    {
        Gate::authorize('leads.view');

        return $this->present($lead);
    }

    public function store(StoreLeadRequest $request, CreateLeadAction $action): LeadResource
    {
        return $this->present($action->execute($request->user(), $request->validated()));
    }

    public function update(UpdateLeadRequest $request, Lead $lead, UpdateLeadAction $action): LeadResource
    {
        return $this->present($action->execute($request->user(), $lead, $request->validated()));
    }

    public function destroy(Request $request, Lead $lead, DeleteLeadAction $action): Response
    {
        $action->execute($request->user(), $lead);

        return response()->noContent();
    }

    /**
     * 8a.2: POST /api/v1/leads/{lead}/convert - the single conversion entry point.
     * Same error shape as the Deals transition endpoint (14.3): 409 when the lead
     * was already converted (carries the current server-side lead), 422 when it
     * cannot be converted yet (e.g. no last name).
     */
    public function convert(ConvertLeadRequest $request, Lead $lead, ConvertLeadAction $action): LeadResource|JsonResponse
    {
        try {
            $converted = $action->execute($request->user(), $lead, $request->validated());
        } catch (LeadNotConvertibleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [],
                'code' => $e->reason,
                'current' => new LeadResource($e->lead->load('owner:id,name')),
            ], $e->reason === LeadNotConvertibleException::ALREADY_CONVERTED ? 409 : 422);
        }

        return $this->present($converted);
    }


    private function present(Lead $lead): LeadResource
    {
        return new LeadResource($lead->load('owner:id,name'));
    }
}
