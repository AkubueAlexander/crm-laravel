<?php

namespace App\Domain\Hr\Actions;

use App\Domain\Hr\Exceptions\LeaveTransitionException;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 13.2a: the single entry point for leave status changes (the 3.0 pattern: expected lock_version, row lock,
 * one allowed-transition map). hr.manage approves/rejects/cancels anything; an employee may cancel their OWN
 * request, only while it is still pending.
 */
final class TransitionLeaveRequestAction
{
    public const TRANSITIONS = [
        'pending' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['cancelled'],
    ];

    public function execute(User $actor, int $leaveId, string $toStatus, int $expectedLockVersion, ?string $note = null): LeaveRequest
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('hr.view');

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => ['Keep the note to 500 characters or fewer.']]);
        }

        return DB::transaction(function () use ($actor, $tenantId, $leaveId, $toStatus, $expectedLockVersion, $note): LeaveRequest {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $leave = LeaveRequest::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($leaveId);

            $this->authorizeChange($actor, $tenantId, $leave, $toStatus);

            if ((int) $leave->lock_version !== $expectedLockVersion) {
                throw LeaveTransitionException::stale($leave->load('employee'));
            }

            $from = (string) $leave->status;

            if (! in_array($toStatus, self::TRANSITIONS[$from] ?? [], true)) {
                throw LeaveTransitionException::invalid($from, $toStatus);
            }

            if ($toStatus === LeaveRequest::STATUS_REJECTED && $note === null) {
                throw LeaveTransitionException::noteRequired();
            }

            $leave->forceFill([
                'status' => $toStatus,
                'lock_version' => (int) $leave->lock_version + 1,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            return $leave->load('employee');
        });
    }

    private function authorizeChange(User $actor, int $tenantId, LeaveRequest $leave, string $toStatus): void
    {
        if (Gate::forUser($actor)->allows('hr.manage')) {
            return;
        }

        $ownsIt = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('id', $leave->employee_id)
            ->where('user_id', $actor->id)
            ->exists();

        if ($toStatus === LeaveRequest::STATUS_CANCELLED && $ownsIt && $leave->status === LeaveRequest::STATUS_PENDING) {
            return;
        }

        throw new AuthorizationException();
    }
}
