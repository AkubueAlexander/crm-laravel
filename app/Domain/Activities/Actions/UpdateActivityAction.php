<?php

namespace App\Domain\Activities\Actions;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateActivityAction
{
    /** @param array<string, mixed> $data Already validated by UpdateActivityRequest. */
    public function execute(User $actor, Activity $activity, array $data): Activity
    {
        Gate::forUser($actor)->authorize('activities.update');

        // The target, tenant and completion state are not editable here.
        $payload = Arr::only($data, ['type', 'subject', 'body', 'due_at', 'owner_id']);

        if (array_key_exists('type', $payload) && ! in_array($payload['type'], Activity::TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['The selected activity type is invalid.']]);
        }

        if (($payload['owner_id'] ?? null) !== null
            && ! Activity::ownerBelongsToTenant((int) $payload['owner_id'])) {
            throw ValidationException::withMessages(['owner_id' => ['The selected owner is invalid.']]);
        }

        $activity->fill($payload);

        // Due date removed or moved into the future: no longer overdue.
        if (array_key_exists('due_at', $payload)
            && ($activity->due_at === null || $activity->due_at->isFuture())) {
            $activity->forceFill(['overdue_at' => null]);
        }

        $activity->save();

        return $activity;
    }
}
