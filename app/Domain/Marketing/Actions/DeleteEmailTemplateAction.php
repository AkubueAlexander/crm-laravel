<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteEmailTemplateAction
{
    public function __invoke(User $actor, int $id): void
    {
        Gate::forUser($actor)->authorize('marketing.manage');

        DB::transaction(function () use ($actor, $id): void {
            // The row lock pairs with the lock TransitionCampaignAction takes on the template when a
            // campaign is scheduled or sent, so the check below cannot race a concurrent schedule.
            $template = EmailTemplate::query()
                ->where('tenant_id', $actor->tenant_id)
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            // 9.4b: a template a scheduled, sending, sent or failed campaign points at is part of that
            // campaign's history. Drafts only lose their template (FK is nullOnDelete).
            $inUse = DB::table('campaigns')
                ->where('tenant_id', $actor->tenant_id)
                ->where('email_template_id', $id)
                ->whereIn('status', Campaign::IN_USE_STATUSES)
                ->exists();

            if ($inUse) {
                throw ValidationException::withMessages([
                    'template' => ['This template is used by a scheduled or sent campaign and cannot be deleted.'],
                ]);
            }

            // 9.6c-3: an ACTIVE automation sends this template; deleting it would make every run of it fail.
            $usedByAutomation = DB::table('automation_steps as s')
                ->join('automations as a', 'a.id', '=', 's.automation_id')
                ->where('s.tenant_id', $actor->tenant_id)
                ->where('a.tenant_id', $actor->tenant_id)
                ->where('a.status', 'active')
                ->where('s.type', 'send_email')
                ->whereRaw("(s.config->>'email_template_id') = ?", [(string) $id])
                ->exists();

            if ($usedByAutomation) {
                throw ValidationException::withMessages([
                    'template' => ['This template is used by an active automation and cannot be deleted. Pause that automation first.'],
                ]);
            }

            $template->delete();
        });
    }
}