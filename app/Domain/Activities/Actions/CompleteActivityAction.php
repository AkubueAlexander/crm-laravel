<?php

namespace App\Domain\Activities\Actions;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CompleteActivityAction
{
    /**
     * Mark an activity done, or reopen it ($completed = false). Idempotent: completing
     * an already-completed activity keeps its original completed_at and is not an error.
     */
    public function execute(User $actor, Activity $activity, bool $completed = true): Activity
    {
        Gate::forUser($actor)->authorize('activities.complete');

        return DB::transaction(function () use ($activity, $completed) {
            // Re-read under a row lock so two racing requests cannot disagree.
            $row = Activity::query()->lockForUpdate()->findOrFail($activity->getKey());

            $row->forceFill([
                'completed_at' => $completed ? ($row->completed_at ?? now()) : null,
            ])->save();

            return $row;
        });
    }
}
