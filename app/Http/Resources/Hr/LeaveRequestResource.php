<?php

namespace App\Http\Resources\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** 13.2a: explicit whitelist of leave-request fields. */
final class LeaveRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $start = $this->start_date;
        $end = $this->end_date;

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->relationLoaded('employee') && $this->employee !== null
                ? ['id' => $this->employee->id, 'name' => $this->employee->name]
                : null,
            'type' => $this->type,
            'start_date' => $start?->format('Y-m-d'),
            'end_date' => $end?->format('Y-m-d'),
            'days' => ($start !== null && $end !== null) ? (int) round(abs($start->diffInDays($end))) + 1 : null,
            'reason' => $this->reason,
            'status' => $this->status,
            'lock_version' => $this->lock_version,
            'requested_by' => $this->requested_by,
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
