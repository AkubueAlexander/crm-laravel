<?php

namespace App\Http\Controllers\Api\Leads;

use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\DeleteLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\ListLeadsRequest;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
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
                // Escape LIKE wildcards; Postgres uses backslash as the default escape.
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
            ->orderBy('id') // stable tiebreaker so pages never overlap
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

    /** Same response shape from every endpoint (owner always present). */
    private function present(Lead $lead): LeadResource
    {
        return new LeadResource($lead->load('owner:id,name'));
    }
}