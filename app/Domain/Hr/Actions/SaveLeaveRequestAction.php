<?php

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Exceptions\LeaveTransitionException;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * 13.2a: create / edit-while-pending. hr.manage may act for any employee; anyone else (hr.view) only for the
 * employee record linked to their own user. Status changes live in TransitionLeaveRequestAction.
 */
final class SaveLeaveRequestAction
{
    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): LeaveRequest
    {
        $tenantId = $this->authorize($actor);
        $clean = $this->validate($data, true);

        return DB::transaction(function () use ($actor, $tenantId, $clean): LeaveRequest {
            $employee = $this->employeeFor($actor, $tenantId, (int) $clean['employee_id']);

            if (! $employee->is_active) {
                throw ValidationException::withMessages(['employee_id' => ['This employee is inactive.']]);
            }

            $this->assertNoOverlap($tenantId, (int) $employee->id, $clean, null);

            $leave = new LeaveRequest();
            $leave->forceFill([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'type' => $clean['type'],
                'start_date' => $clean['start_date'],
                'end_date' => $clean['end_date'],
                'reason' => $clean['reason'],
                'status' => LeaveRequest::STATUS_PENDING,
                'lock_version' => 0,
                'requested_by' => $actor->id,
            ])->save();

            return $leave->load('employee');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data): LeaveRequest
    {
        $tenantId = $this->authorize($actor);
        $clean = $this->validate($data, false);

        return DB::transaction(function () use ($actor, $tenantId, $id, $clean): LeaveRequest {
            $leave = LeaveRequest::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            $employee = $this->employeeFor($actor, $tenantId, (int) $leave->employee_id);

            if ((int) $leave->lock_version !== (int) $clean['lock_version']) {
                throw LeaveTransitionException::stale($leave->load('employee'));
            }
            if ($leave->status !== LeaveRequest::STATUS_PENDING) {
                throw LeaveTransitionException::notEditable();
            }

            $this->assertNoOverlap($tenantId, (int) $employee->id, $clean, (int) $leave->id);

            $leave->forceFill([
                'type' => $clean['type'],
                'start_date' => $clean['start_date'],
                'end_date' => $clean['end_date'],
                'reason' => $clean['reason'],
                'lock_version' => (int) $leave->lock_version + 1,
            ])->save();

            return $leave->load('employee');
        });
    }

    private function authorize(User $actor): int
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('hr.view');

        return $tenantId;
    }

    private function employeeFor(User $actor, int $tenantId, int $employeeId): Employee
    {
        $employee = Employee::query()->where('tenant_id', $tenantId)->find($employeeId);

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => ['Choose an employee.']]);
        }

        if (! Gate::forUser($actor)->allows('hr.manage') && (int) $employee->user_id !== (int) $actor->id) {
            throw new AuthorizationException();
        }

        return $employee;
    }

    /** @param array<string, mixed> $clean */
    private function assertNoOverlap(int $tenantId, int $employeeId, array $clean, ?int $ignoreId): void
    {
        // Serialise writers per employee so two requests cannot both pass the check.
        DB::statement('select pg_advisory_xact_lock(7104, '.$employeeId.')');

        $query = DB::table('leave_requests')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
            ->where('start_date', '<=', $clean['end_date'])
            ->where('end_date', '>=', $clean['start_date']);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'start_date' => ['These dates overlap another pending or approved leave request.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $errors = [];

        $employeeId = $data['employee_id'] ?? null;
        if ($creating && (! (is_int($employeeId) || (is_string($employeeId) && ctype_digit($employeeId))) || (int) $employeeId < 1)) {
            $errors['employee_id'] = 'Choose an employee.';
        }

        $type = $data['type'] ?? null;
        if (! is_string($type) || ! in_array($type, LeaveRequest::TYPES, true)) {
            $errors['type'] = 'Choose a leave type.';
        }

        $start = $this->parseDate($data['start_date'] ?? null);
        $end = $this->parseDate($data['end_date'] ?? null);

        if ($start === null) {
            $errors['start_date'] = 'Enter a valid start date.';
        }
        if ($end === null) {
            $errors['end_date'] = 'Enter a valid end date.';
        }
        if ($start !== null && $end !== null) {
            if ($end->lt($start)) {
                $errors['end_date'] = 'The end date cannot be before the start date.';
            } elseif ($start->diffInDays($end, true) > 365) {
                $errors['end_date'] = 'A leave request cannot span more than a year.';
            }
        }

        $reason = $data['reason'] ?? null;
        if ($reason !== null && (! is_string($reason) || mb_strlen($reason) > 1000)) {
            $errors['reason'] = 'Keep the reason to 1000 characters or fewer.';
        }

        $lock = $data['lock_version'] ?? null;
        if (! $creating && (! (is_int($lock) || (is_string($lock) && ctype_digit($lock))))) {
            $errors['lock_version'] = 'Missing version.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $m) => [$m], $errors));
        }

        $reason = is_string($reason) ? trim($reason) : '';

        return [
            'employee_id' => $creating ? (int) $employeeId : null,
            'type' => $type,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'reason' => $reason === '' ? null : $reason,
            'lock_version' => $creating ? 0 : (int) $lock,
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }
}
