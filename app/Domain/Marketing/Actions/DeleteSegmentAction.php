<?php

namespace App\Domain\Marketing\Actions;

use App\Models\Campaign;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class DeleteSegmentAction
{
    public function execute(User $actor, int $segmentId): void
    {
        Gate::forUser($actor)->authorize('marketing.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        DB::transaction(function () use ($segmentId, $tenantId): void {
            // The row lock pairs with the lock TransitionCampaignAction takes on the segment when a campaign
            // is scheduled or sent, so the check below cannot race a concurrent schedule.
            $segment = Segment::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($segmentId);

            // 9.4a: a segment a scheduled, sending, sent or failed campaign points at is part of that
            // campaign's history. Drafts only lose their audience (FK is nullOnDelete).
            $inUse = DB::table('campaigns')
                ->where('tenant_id', $tenantId)
                ->where('segment_id', $segmentId)
                ->whereIn('status', Campaign::IN_USE_STATUSES)
                ->exists();

            if ($inUse) {
                throw ValidationException::withMessages([
                    'segment' => ['This segment is used by a scheduled or sent campaign and cannot be deleted.'],
                ]);
            }

            $segment->delete();
        });
    }
}