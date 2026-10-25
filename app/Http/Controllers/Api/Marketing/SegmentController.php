<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Domain\Marketing\Actions\CreateSegmentAction;
use App\Domain\Marketing\Actions\DeleteSegmentAction;
use App\Domain\Marketing\Actions\UpdateSegmentAction;
use App\Domain\Marketing\Segments\SegmentDsl;
use App\Domain\Marketing\Segments\SegmentQueryBuilder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\ListSegmentsRequest;
use App\Http\Requests\Marketing\PreviewSegmentRequest;
use App\Http\Requests\Marketing\SaveSegmentRequest;
use App\Http\Resources\SegmentResource;
use App\Models\Contact;
use App\Models\Segment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class SegmentController extends Controller
{
    public const PREVIEW_SAMPLE_SIZE = 5;

    public function index(ListSegmentsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $q = trim((string) ($v['q'] ?? ''));

        $segments = Segment::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('description', 'ilike', $like));
            })
            ->orderBy($v['sort'] ?? 'created_at', $v['direction'] ?? 'desc')
            ->orderBy('id', $v['direction'] ?? 'desc') // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return SegmentResource::collection($segments);
    }

    /** The builder's catalog: fields, their operators and value kinds. The SPA never hardcodes these. */
    public function fields(): JsonResponse
    {
        Gate::authorize('marketing.view');

        return response()->json([
            'data' => SegmentDsl::fields(),
            'max_conditions' => SegmentDsl::MAX_CONDITIONS,
            'match_modes' => Segment::MATCH_MODES,
        ]);
    }

    /** How many contacts a (possibly unsaved) definition matches, plus a few of them. Never writes. */
    public function preview(PreviewSegmentRequest $request): JsonResponse
    {
        $v = $request->validated();

        SegmentDsl::assertValid($v['match_mode'], $v['conditions']);

        $query = SegmentQueryBuilder::contactsFor(
            (int) $request->user()->tenant_id,
            $v['match_mode'],
            SegmentDsl::normalize($v['conditions']),
        );

        $count = (clone $query)->count();

        // Names and emails are contact data: only for actors who may see contacts at all.
        $sample = [];

        if ($request->user()->can('contacts.view')) {
            $sample = $query
                ->orderBy('contacts.last_name')
                ->orderBy('contacts.id')
                ->limit(self::PREVIEW_SAMPLE_SIZE)
                ->get(['contacts.id', 'contacts.first_name', 'contacts.last_name', 'contacts.email'])
                ->map(fn (Contact $c) => ['id' => $c->id, 'name' => $c->full_name, 'email' => $c->email])
                ->all();
        }

        return response()->json(['data' => ['count' => $count, 'sample' => $sample]]);
    }

    // {segment} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
    public function show(Request $request, int $segment): SegmentResource
    {
        Gate::authorize('marketing.view');

        return new SegmentResource(
            Segment::query()->where('tenant_id', (int) $request->user()->tenant_id)->findOrFail($segment),
        );
    }

    public function store(SaveSegmentRequest $request, CreateSegmentAction $action): JsonResponse
    {
        $segment = $action->execute($request->user(), $request->validated());

        return (new SegmentResource($segment))->response()->setStatusCode(201);
    }

    public function update(SaveSegmentRequest $request, int $segment, UpdateSegmentAction $action): SegmentResource
    {
        return new SegmentResource($action->execute($request->user(), $segment, $request->validated()));
    }

    public function destroy(Request $request, int $segment, DeleteSegmentAction $action): Response
    {
        $action->execute($request->user(), $segment);

        return response()->noContent();
    }
}
