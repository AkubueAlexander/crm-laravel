<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Domain\Marketing\Actions\TransitionCampaignAction;
use App\Domain\Marketing\Exceptions\CampaignTransitionException;
use App\Domain\Marketing\Sending\EmailSuppressions;
use App\Domain\Marketing\Sending\SendRenderer;
use App\Domain\Marketing\Sending\UnsubscribeToken;
use App\Domain\Marketing\Templates\TemplateVariables;
use App\Domain\Tenant\TenantContext;
use App\Mail\CampaignEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 9.4c: sends up to CHUNK pending recipients of one campaign, then either re-dispatches itself (more
 * pending) or finalizes the campaign. Sequential on purpose: simple, resumable and cancellable.
 *
 * - Every recipient is claimed with FOR UPDATE SKIP LOCKED inside its own transaction and re-checked as
 *   'pending' there, so a duplicate or retried job can never send the same row twice at the same time.
 * - One recipient failing (or being suppressed) never fails the chunk.
 * - The campaign status is re-read before each chunk: a cancel stops the send within one chunk.
 * - The timeout stays under the redis retry_after (90s) so a running job is never re-queued underneath itself.
 */
class SendCampaignChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CHUNK = 25;

    public int $tries = 3;

    public int $timeout = 80;

    public function __construct(public readonly int $tenantId, public readonly int $campaignId)
    {
    }

    public function handle(TransitionCampaignAction $transition): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        TenantContext::set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            $campaign = DB::table('campaigns')
                ->where('tenant_id', $this->tenantId)
                ->where('id', $this->campaignId)
                ->first(['id', 'status', 'email_template_id']);

            // Cancelled, finished or gone: stop quietly.
            if ($campaign === null || $campaign->status !== 'sending') {
                return;
            }

            $template = DB::table('email_templates')
                ->where('tenant_id', $this->tenantId)
                ->where('id', $campaign->email_template_id)
                ->first(['subject', 'body']);

            if ($template === null) {
                self::failCampaign($this->tenantId, $this->campaignId);

                return;
            }

            $sender = Schema::hasColumn('tenants', 'name')
                ? (string) DB::table('tenants')->where('id', $this->tenantId)->value('name')
                : '';

            $processed = 0;
            while ($processed < self::CHUNK && $this->sendNext($template, $sender)) {
                $processed++;
            }

            self::syncCounters($this->tenantId, $this->campaignId);

            if (self::pendingCount($this->tenantId, $this->campaignId) > 0) {
                $next = self::dispatch($this->tenantId, $this->campaignId);
                if ($processed === 0) {
                    // Nothing claimable right now (another worker holds the rows): do not spin.
                    $next->delay(now()->addSeconds(5));
                }
            } else {
                self::finalize($transition, $this->tenantId, $this->campaignId);
            }
        } finally {
            TenantContext::clear();
        }
    }

    /** All attempts used up (crash, timeout, unexpected error): the campaign is marked failed. */
    public function failed(Throwable $e): void
    {
        self::failCampaign($this->tenantId, $this->campaignId);
    }

    private function sendNext(object $template, string $sender): bool
    {
        return DB::transaction(function () use ($template, $sender): bool {
            $recipient = DB::table('campaign_recipients')
                ->where('tenant_id', $this->tenantId)
                ->where('campaign_id', $this->campaignId)
                ->where('status', 'pending')
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if ($recipient === null) {
                return false;
            }

            // Unsubscribed or bounced after the snapshot was taken (or while the send was running).
            if (EmailSuppressions::isSuppressed($this->tenantId, (string) $recipient->email)) {
                $this->mark((int) $recipient->id, 'skipped', 'Suppressed (unsubscribed, bounced or complained).', false);

                return true;
            }

            try {
                $unsubscribeUrl = UnsubscribeToken::url(UnsubscribeToken::make($this->tenantId, (int) $recipient->id));

                $mail = SendRenderer::render(
                    (string) $template->subject,
                    (string) $template->body,
                    TemplateVariables::valuesFor($recipient->name, (string) $recipient->email, $recipient->job_title),
                    $unsubscribeUrl,
                    $sender,
                    null,
                );

                Mail::to((string) $recipient->email)->send(
                    new CampaignEmail($mail['subject'], $mail['html'], $mail['text'], $mail['headers']),
                );

                $this->mark((int) $recipient->id, 'sent', null, true);
            } catch (Throwable $e) {
                report($e);
                $this->mark((int) $recipient->id, 'failed', mb_substr($e->getMessage(), 0, 500), true);
            }

            return true;
        });
    }

    private function mark(int $recipientId, string $status, ?string $error, bool $attempted): void
    {
        $values = [
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
            'updated_at' => now(),
        ];
        if ($attempted) {
            $values['attempts'] = DB::raw('attempts + 1');
        }

        DB::table('campaign_recipients')
            ->where('tenant_id', $this->tenantId)
            ->where('id', $recipientId)
            ->update($values);
    }

    // ------------------------------------------------------------------ shared helpers (also used by StartCampaignSendJob)

    public static function pendingCount(int $tenantId, int $campaignId): int
    {
        return DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Counters are recomputed from the recipient rows (the source of truth), never incremented, so a retry
     * or a duplicate job cannot drift them. They are not part of the optimistic lock: lock_version is untouched.
     */
    public static function syncCounters(int $tenantId, int $campaignId): void
    {
        $counts = DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->selectRaw("count(*) as total, count(*) filter (where status = 'sent') as sent, count(*) filter (where status = 'failed') as failed")
            ->first();

        DB::table('campaigns')
            ->where('tenant_id', $tenantId)
            ->where('id', $campaignId)
            ->update([
                'recipients_count' => (int) $counts->total,
                'sent_count' => (int) $counts->sent,
                'failed_count' => (int) $counts->failed,
                'updated_at' => now(),
            ]);
    }

    /** Caller must have set TenantContext. sent if anything went out (or nothing needed to); failed only if every attempt failed. */
    public static function finalize(TransitionCampaignAction $transition, int $tenantId, int $campaignId): void
    {
        self::syncCounters($tenantId, $campaignId);

        $row = DB::table('campaigns')
            ->where('tenant_id', $tenantId)
            ->where('id', $campaignId)
            ->first(['recipients_count', 'sent_count', 'failed_count']);

        if ($row === null) {
            return;
        }

        $allFailed = (int) $row->recipients_count > 0 && (int) $row->sent_count === 0 && (int) $row->failed_count > 0;

        try {
            $transition->executeSystem($tenantId, $campaignId, $allFailed ? 'failed' : 'sent');
        } catch (CampaignTransitionException) {
            // Cancelled while the last chunk was running: leave it cancelled.
        }
    }

    public static function failCampaign(int $tenantId, int $campaignId): void
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return;
        }

        TenantContext::set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            self::syncCounters($tenantId, $campaignId);
            app(TransitionCampaignAction::class)->executeSystem($tenantId, $campaignId, 'failed');
        } catch (CampaignTransitionException) {
            // Already finished or cancelled.
        } catch (Throwable $e) {
            report($e);
        } finally {
            TenantContext::clear();
        }
    }
}