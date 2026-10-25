<?php

namespace App\Domain\Marketing\Actions;

use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final class DeleteSegmentAction
{
    public function execute(User $actor, int $segmentId): void
    {
        Gate::forUser($actor)->authorize('marketing.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        DB::transaction(function () use ($segmentId, $tenantId): void {
            $segment = Segment::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($segmentId);

            // 9.4 will refuse deleting a segment a scheduled or sent campaign points at; nothing references segments yet.
            $segment->delete();
        });
    }
}
