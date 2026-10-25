<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Segments\SegmentDsl;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class UpdateSegmentAction
{
    /** @param array{name: string, description?: string|null, match_mode: string, conditions: array<int, mixed>} $data */
    public function execute(User $actor, int $segmentId, array $data): Segment
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('marketing.manage');

        SegmentDsl::assertValid($data['match_mode'] ?? null, $data['conditions'] ?? null);

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        try {
            return DB::transaction(function () use ($segmentId, $data, $tenantId): Segment {
                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                // The row lock serializes concurrent edits; the last write wins (no lock_version until a segment has a lifecycle).
                $segment = Segment::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($segmentId);

                $segment->forceFill([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'match_mode' => $data['match_mode'],
                    'conditions' => SegmentDsl::normalize($data['conditions']),
                ])->save();

                return $segment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A segment with this name already exists.']]);
        }
    }
}
