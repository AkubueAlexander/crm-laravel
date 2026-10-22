<?php

namespace App\Http\Controllers\Api\Activities;

use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Actions\CreateActivityAction;
use App\Domain\Activities\Actions\DeleteActivityAction;
use App\Domain\Activities\Actions\UpdateActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activities\ListActivitiesRequest;
use App\Http\Requests\Activities\StoreActivityRequest;
use App\Http\Requests\Activities\UpdateActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class ActivityController extends Controller
{
    /** GET /api/v1/activities?activityable_type=lead&activityable_id=12 - one record's timeline. */
    public function index(ListActivitiesRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $alias = $v['activityable_type'];

        // Reading a record's timeline requires being able to see that record.
        Gate::authorize(Activity::targetViewPermission($alias));

        // Tenant scope applies: another tenant's (or a missing) record is a 404.
        $class = Activity::targetClass($alias);
        $class::query()->findOrFail($v['activityable_id']);

        $activities = Activity::query()
            ->with('owner:id,name')
            ->where('activityable_type', $alias)
            ->where('activityable_id', $v['activityable_id'])
            ->when($v['status'] ?? null, fn ($query, $status) => $status === 'open'
                ? $query->whereNull('completed_at')
                : $query->whereNotNull('completed_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return ActivityResource::collection($activities);
    }

    public function store(StoreActivityRequest $request, CreateActivityAction $action): ActivityResource
    {
        $v = $request->validated();

        return $this->present($action->execute(
            $request->user(),
            $v['activityable_type'],
            (int) $v['activityable_id'],
            $v,
        ));
    }

    public function update(UpdateActivityRequest $request, Activity $activity, UpdateActivityAction $action): ActivityResource
    {
        return $this->present($action->execute($request->user(), $activity, $request->validated()));
    }

    /** POST /api/v1/activities/{activity}/complete  (body: { completed?: bool }, default true). */
    public function complete(Request $request, Activity $activity, CompleteActivityAction $action): ActivityResource
    {
        return $this->present($action->execute(
            $request->user(),
            $activity,
            $request->boolean('completed', true),
        ));
    }

    public function destroy(Request $request, Activity $activity, DeleteActivityAction $action): Response
    {
        $action->execute($request->user(), $activity);

        return response()->noContent();
    }

    private function present(Activity $activity): ActivityResource
    {
        return new ActivityResource($activity->load('owner:id,name'));
    }
}
