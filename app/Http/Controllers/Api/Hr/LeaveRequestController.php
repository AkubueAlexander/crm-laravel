<?php

namespace App\Http\Controllers\Api\Hr;

use App\Domain\Hr\Actions\SaveLeaveRequestAction;
use App\Domain\Hr\Actions\TransitionLeaveRequestAction;
use App\Domain\Hr\Exceptions\LeaveTransitionException;
use App\Http\Resources\Hr\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** 13.2a: thin HTTP layer; the Actions are the authorization/validation boundary. */
final class LeaveRequestController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('hr.view');

        $v = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', LeaveRequest::STATUSES)],
            'employee_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'in:start_date,-start_date,created_at,-created_at'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $query = LeaveRequest::query()->where('tenant_id', $tenantId)->with('employee');

        // Leave is private: without hr.manage a user only ever sees their own requests.
        if (! Gate::allows('hr.manage')) {
            $own = Employee::query()->where('tenant_id', $tenantId)->where('user_id', $request->user()->id)->pluck('id');
            $query->whereIn('employee_id', $own);
        }

        if (! empty($v['status'])) {
            $query->where('status', $v['status']);
        }
        if (! empty($v['employee_id'])) {
            $query->where('employee_id', (int) $v['employee_id']);
        }
        if (! empty($v['from'])) {
            $query->where('end_date', '>=', $v['from']);
        }
        if (! empty($v['to'])) {
            $query->where('start_date', '<=', $v['to']);
        }

        $sort = $v['sort'] ?? '-start_date';
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderByDesc('id');

        return LeaveRequestResource::collection($query->paginate((int) ($v['per_page'] ?? 25)));
    }

    /** Employees out on a date (default today): ids only, for planners and assignment UIs. */
    public function out(Request $request): JsonResponse
    {
        Gate::authorize('hr.view');

        $v = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $v['date'] ?? now()->toDateString();

        return response()->json([
            'date' => $date,
            'employee_ids' => LeaveRequest::outEmployeeIds((int) $request->user()->tenant_id, $date),
        ]);
    }

    public function show(Request $request, int $id): LeaveRequestResource
    {
        Gate::authorize('hr.view');

        $tenantId = (int) $request->user()->tenant_id;
        $leave = LeaveRequest::query()->where('tenant_id', $tenantId)->with('employee')->findOrFail($id);

        if (! Gate::allows('hr.manage')) {
            $owns = Employee::query()->where('tenant_id', $tenantId)->where('id', $leave->employee_id)->where('user_id', $request->user()->id)->exists();

            if (! $owns) {
                throw new AuthorizationException();
            }
        }

        return new LeaveRequestResource($leave);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer'],
            'type' => ['required', 'string'],
            'start_date' => ['required', 'string'],
            'end_date' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        $leave = (new SaveLeaveRequestAction())->create(
            $request->user(),
            $request->only(['employee_id', 'type', 'start_date', 'end_date', 'reason']),
        );

        return (new LeaveRequestResource($leave))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): LeaveRequestResource|JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string'],
            'start_date' => ['required', 'string'],
            'end_date' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
            'lock_version' => ['required', 'integer'],
        ]);

        try {
            $leave = (new SaveLeaveRequestAction())->update(
                $request->user(),
                $id,
                $request->only(['type', 'start_date', 'end_date', 'reason', 'lock_version']),
            );
        } catch (LeaveTransitionException $e) {
            return $this->fail($e);
        }

        return new LeaveRequestResource($leave);
    }

    public function transition(Request $request, int $id): LeaveRequestResource|JsonResponse
    {
        $request->validate([
            'to_status' => ['required', 'string'],
            'lock_version' => ['required', 'integer'],
            'note' => ['nullable', 'string'],
        ]);

        try {
            $leave = (new TransitionLeaveRequestAction())->execute(
                $request->user(),
                $id,
                (string) $request->input('to_status'),
                (int) $request->input('lock_version'),
                $request->input('note'),
            );
        } catch (LeaveTransitionException $e) {
            return $this->fail($e);
        }

        return new LeaveRequestResource($leave);
    }

    private function fail(LeaveTransitionException $e): JsonResponse
    {
        if ($e->isStale()) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
                'current' => $e->leave !== null ? (new LeaveRequestResource($e->leave))->resolve() : null,
            ], 409);
        }

        return response()->json([
            'message' => $e->getMessage(),
            'errors' => (object) [],
            'code' => $e->reason,
        ], 422);
    }
}
