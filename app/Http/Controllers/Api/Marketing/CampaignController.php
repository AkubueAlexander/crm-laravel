<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Domain\Marketing\Actions\CreateCampaignAction;
use App\Domain\Marketing\Actions\DeleteCampaignAction;
use App\Domain\Marketing\Actions\UpdateCampaignAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\ListCampaignsRequest;
use App\Http\Requests\Marketing\SaveCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class CampaignController extends Controller
{
    public function index(ListCampaignsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $q = trim((string) ($v['q'] ?? ''));

        $campaigns = Campaign::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('subject', 'ilike', $like));
            })
            ->when($v['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($v['sort'] ?? 'created_at', $v['direction'] ?? 'desc')
            ->orderBy('id', $v['direction'] ?? 'desc') // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return CampaignResource::collection($campaigns);
    }

    // {campaign} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
    public function show(Request $request, int $campaign): CampaignResource
    {
        Gate::authorize('marketing.view');

        return new CampaignResource(
            Campaign::query()->where('tenant_id', (int) $request->user()->tenant_id)->findOrFail($campaign),
        );
    }

    public function store(SaveCampaignRequest $request, CreateCampaignAction $action): JsonResponse
    {
        $campaign = $action->execute($request->user(), $request->validated());

        return (new CampaignResource($campaign))->response()->setStatusCode(201);
    }

    public function update(SaveCampaignRequest $request, int $campaign, UpdateCampaignAction $action): CampaignResource
    {
        return new CampaignResource($action->execute($request->user(), $campaign, $request->validated()));
    }

    public function destroy(Request $request, int $campaign, DeleteCampaignAction $action): Response
    {
        $action->execute($request->user(), $campaign);

        return response()->noContent();
    }
}
