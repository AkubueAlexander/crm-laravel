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

final class CreateSegmentAction
{
    /** @param array{name: string, description?: string|null, match_mode: string, conditions: array<int, mixed>} $data */
    public function execute(User $actor, array $data): Segment
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('marketing.manage');

        // The DSL is validated again here: the Action is the boundary, the Form Request is the UX of it.
        SegmentDsl::assertValid($data['match_mode'] ?? null, $data['conditions'] ?? null);

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        try {
            return DB::transaction(function () use ($actor, $data, $tenantId): Segment {
                // Tenant and creator come from the session, never from the request body (1.3).
                $segment = new Segment();
                $segment->forceFill([
                    'tenant_id' => $tenantId,
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'match_mode' => $data['match_mode'],
                    'conditions' => SegmentDsl::normalize($data['conditions']),
                    'created_by' => $actor->id,
                ])->save();

                return $segment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A segment with this name already exists.']]);
        }
    }
}
