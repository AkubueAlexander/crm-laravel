<?php

namespace App\Domain\Activities\Actions;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteActivityAction
{
    public function execute(User $actor, Activity $activity): void
    {
        Gate::forUser($actor)->authorize('activities.delete');

        $activity->delete();
    }
}
