<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Exceptions\CampaignTransitionException;
use App\Models\Campaign;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * 9.4a: the single entry point for campaign status changes (same principle as 3.0).
 * - execute(): a user action; permission re-checked here (2.4), optimistic lock_version check, row lock.
 * - executeSystem(): for the scheduler and the send jobs (9.4c); no actor, explicit tenant id.
 * Both go through apply(), so the allowed-transition map is enforced in exactly one place.
 */
final class TransitionCampaignAction
{
    public const TRANSITIONS = [
        'draft' => ['scheduled', 'sending'],
        'scheduled' => ['draft', 'sending', 'cancelled'],
        'sending' => ['sent', 'failed', 'cancelled'],
        'failed' => ['sending', 'cancelled'],
        'sent' => [],
        'cancelled' => [],
    ];

    /** Statuses a person may request. 'sent' and 'failed' are only ever set by the send jobs. */
    public const USER_TARGETS = ['draft', 'scheduled', 'sending', 'cancelled'];

    public function execute(
        User $actor,
        int $campaignId,
        string $toStatus,
        int $expectedLockVersion,
        ?CarbonInterface $scheduledAt = null,
    ): Campaign {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('marketing.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        return DB::transaction(function () use ($campaignId, $toStatus, $expectedLockVersion, $scheduledAt, $tenantId): Campaign {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $campaign = Campaign::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($campaignId);

            if ((int) $campaign->lock_version !== $expectedLockVersion) {
                throw CampaignTransitionException::stale($campaign);
            }

            if (! in_array($toStatus, self::USER_TARGETS, true)) {
                throw CampaignTransitionException::invalid((string) $campaign->status, $toStatus);
            }

            return $this->apply($campaign, $toStatus, $scheduledAt, $tenantId);
        });
    }

    public function executeSystem(int $tenantId, int $campaignId, string $toStatus): Campaign
    {
        return DB::transaction(function () use ($tenantId, $campaignId, $toStatus): Campaign {
            $campaign = Campaign::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($campaignId);

            return $this->apply($campaign, $toStatus, null, $tenantId);
        });
    }

    private function apply(Campaign $campaign, string $to, ?CarbonInterface $scheduledAt, int $tenantId): Campaign
    {
        $from = (string) $campaign->status;

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw CampaignTransitionException::invalid($from, $to);
        }

        if ($to === Campaign::STATUS_SCHEDULED || $to === Campaign::STATUS_SENDING) {
            $this->assertAudience($campaign, $tenantId);
        }

        $attributes = [
            'status' => $to,
            'lock_version' => (int) $campaign->lock_version + 1,
        ];

        $attributes += match ($to) {
            Campaign::STATUS_SCHEDULED => ['scheduled_at' => $this->requireFuture($scheduledAt)],
            Campaign::STATUS_DRAFT => ['scheduled_at' => null],
            Campaign::STATUS_SENDING => ['started_at' => $campaign->started_at ?? now(), 'completed_at' => null],
            Campaign::STATUS_SENT => ['sent_at' => now(), 'completed_at' => now()],
            default => ['completed_at' => now()], // failed, cancelled
        };

        $campaign->forceFill($attributes)->save();

        // 9.4c dispatches the start job from here (after commit) when $to is 'sending';
        // 9.4d broadcasts the status change.

        return $campaign;
    }

    /**
     * The campaign needs a segment and a template that still exist in this tenant. Both rows are locked
     * for the rest of the transaction, so a concurrent delete either finishes first (we refuse here) or
     * waits and then sees this campaign as scheduled/sending (the delete Actions refuse).
     */
    private function assertAudience(Campaign $campaign, int $tenantId): void
    {
        $segmentId = $campaign->segment_id;
        $templateId = $campaign->email_template_id;

        if ($segmentId === null || $templateId === null) {
            throw CampaignTransitionException::audienceMissing();
        }

        $segment = DB::table('segments')->where('tenant_id', $tenantId)->where('id', $segmentId)->lockForUpdate()->value('id');
        $template = DB::table('email_templates')->where('tenant_id', $tenantId)->where('id', $templateId)->lockForUpdate()->value('id');

        if ($segment === null || $template === null) {
            throw CampaignTransitionException::audienceMissing();
        }
    }

    private function requireFuture(?CarbonInterface $scheduledAt): CarbonInterface
    {
        if ($scheduledAt === null) {
            throw CampaignTransitionException::scheduleInvalid('Choose a date and time to send this campaign.');
        }

        if (! $scheduledAt->isFuture()) {
            throw CampaignTransitionException::scheduleInvalid('The scheduled time must be in the future.');
        }

        return $scheduledAt;
    }
}