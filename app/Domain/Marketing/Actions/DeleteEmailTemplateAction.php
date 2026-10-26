<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteEmailTemplateAction
{
    public function __invoke(User $actor, int $id): void
    {
        Gate::forUser($actor)->authorize('marketing.manage');

        // TODO(9.4): once campaigns reference a template, refuse deleting one used by a scheduled
        // or sent campaign (same rule as segments).
        DB::transaction(function () use ($actor, $id): void {
            $template = EmailTemplate::query()
                ->where('tenant_id', $actor->tenant_id)
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $template->delete();
        });
    }
}