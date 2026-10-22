<?php

namespace App\Domain\Activities\Actions;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateActivityAction
{
    /**
     * @param string $targetType Alias from Activity::TARGETS (lead, contact, account, deal).
     * @param array<string, mixed> $data Already validated by StoreActivityRequest.
     */
    public function execute(User $actor, string $targetType, int $targetId, array $data): Activity
    {
        Gate::forUser($actor)->authorize('activities.create');

        $class = Activity::targetClass($targetType);
        if ($class === null) {
            throw ValidationException::withMessages([
                'activityable_type' => ['The selected target type is invalid.'],
            ]);
        }

        // You may only log activity against a record you are allowed to see.
        Gate::forUser($actor)->authorize(Activity::targetViewPermission($targetType));

        // Tenant global scope applies: another tenant's record (or a missing or
        // soft-deleted one) is simply not found. Fails closed with no tenant context.
        $target = $class::query()->find($targetId);
        if ($target === null) {
            throw ValidationException::withMessages([
                'activityable_id' => ['The selected record does not exist.'],
            ]);
        }

        $payload = Arr::only($data, ['type', 'subject', 'body', 'due_at', 'owner_id']);

        if (! in_array($payload['type'] ?? null, Activity::TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['The selected activity type is invalid.']]);
        }

        $payload['owner_id'] = $payload['owner_id'] ?? $actor->getKey();
        if (! Activity::ownerBelongsToTenant((int) $payload['owner_id'])) {
            throw ValidationException::withMessages(['owner_id' => ['The selected owner is invalid.']]);
        }

        return Activity::create($payload + [
            'activityable_type' => $targetType,
            'activityable_id' => $target->getKey(),
        ]);
    }
}
