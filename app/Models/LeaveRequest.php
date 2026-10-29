<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** 13.2a: a leave request. Never mass-assigned: SaveLeaveRequestAction / TransitionLeaveRequestAction write it. */
class LeaveRequest extends Model
{
    use BelongsToTenant;

    public const TYPES = ['annual', 'sick', 'unpaid', 'other'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $fillable = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'decided_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Availability (13.2): ids of employees with an APPROVED leave covering the date. Plain query with an
     * explicit tenant predicate; usable from automations and assignment code.
     *
     * @return array<int, int>
     */
    public static function outEmployeeIds(int $tenantId, string $date): array
    {
        $day = Carbon::parse($date)->toDateString();

        return DB::table('leave_requests')
            ->where('tenant_id', $tenantId)
            ->where('status', self::STATUS_APPROVED)
            ->where('start_date', '<=', $day)
            ->where('end_date', '>=', $day)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public static function isOutOn(int $tenantId, int $employeeId, string $date): bool
    {
        return in_array($employeeId, self::outEmployeeIds($tenantId, $date), true);
    }
}
