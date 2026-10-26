<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Automations;

use App\Domain\Marketing\Sending\EmailSuppressions;
use App\Domain\Marketing\Sending\EnrollmentUnsubscribeToken;
use App\Domain\Marketing\Sending\SendRenderer;
use App\Domain\Marketing\Templates\TemplateVariables;
use App\Mail\CampaignEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 9.6c-2: executes the steps of ONE enrollment. Plain DB access with explicit tenant predicates.
 *
 * - Each step runs in its own transaction with the enrollment row locked, and re-reads the automation and the
 *   step, so a pause, an edit or a deleted template is always seen.
 * - Idempotent: the caller passes the step the enrollment was at when it was queued; if the row has moved on
 *   (a duplicate or retried job) nothing happens ("stale").
 * - current_step is the position of the LAST completed step. A delay completes by pushing next_run_at out; an
 *   email completes by being sent, and the loop continues with the next step right away.
 * - Delivery is at-least-once: a crash between the mail leaving and the commit can send that one email again.
 * - A paused or draft automation stops the sends; the lease is released so the enrollment resumes by itself
 *   when the automation is active again.
 *
 * Outcomes: waiting | completed | failed | cancelled | paused | stale | inactive | gone | sent (internal).
 */
final class AutomationStepRunner
{
    public const MAX_STEPS_PER_RUN = 25;

    public static function advance(int $tenantId, int $enrollmentId, ?int $expectedStep): string
    {
        $expected = $expectedStep;
        $outcome = 'gone';

        for ($i = 0; $i < self::MAX_STEPS_PER_RUN; $i++) {
            $result = self::step($tenantId, $enrollmentId, $expected);
            $outcome = $result['outcome'];

            if ($outcome !== 'sent') {
                break;
            }
            $expected = $result['step'];
        }

        return $outcome;
    }

    /** @return array{outcome: string, step: int|null} */
    private static function step(int $tenantId, int $enrollmentId, ?int $expected): array
    {
        return DB::transaction(function () use ($tenantId, $enrollmentId, $expected): array {
            $enrollment = DB::table('automation_enrollments')
                ->where('tenant_id', $tenantId)
                ->where('id', $enrollmentId)
                ->lock('for update')
                ->first();

            if ($enrollment === null) {
                return ['outcome' => 'gone', 'step' => null];
            }

            $current = $enrollment->current_step === null ? null : (int) $enrollment->current_step;

            if ($enrollment->status !== 'active') {
                return ['outcome' => 'inactive', 'step' => $current];
            }
            if ($current !== $expected) {
                return ['outcome' => 'stale', 'step' => $current];
            }

            $automation = DB::table('automations')
                ->where('tenant_id', $tenantId)
                ->where('id', $enrollment->automation_id)
                ->first(['id', 'status']);

            if ($automation === null || $automation->status !== 'active') {
                // Release the lease; the run-due command only picks up enrollments of ACTIVE automations.
                self::update($tenantId, $enrollmentId, ['next_run_at' => now()]);

                return ['outcome' => 'paused', 'step' => $current];
            }

            $next = DB::table('automation_steps')
                ->where('tenant_id', $tenantId)
                ->where('automation_id', $automation->id)
                ->where('position', '>', $current ?? -1)
                ->orderBy('position')
                ->first(['position', 'type', 'config']);

            if ($next === null) {
                self::update($tenantId, $enrollmentId, [
                    'status' => 'completed',
                    'completed_at' => now(),
                    'next_run_at' => null,
                    'last_error' => null,
                ]);

                return ['outcome' => 'completed', 'step' => $current];
            }

            $config = json_decode((string) $next->config, true);
            $config = is_array($config) ? $config : [];
            $position = (int) $next->position;

            if ($next->type === 'delay') {
                return self::runDelay($tenantId, $enrollmentId, $position, $config);
            }
            if ($next->type === 'send_email') {
                return self::runSendEmail($tenantId, $enrollment, $position, $config);
            }

            return self::finish($tenantId, $enrollmentId, 'failed', 'Unknown step type.', $current);
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{outcome: string, step: int|null}
     */
    private static function runDelay(int $tenantId, int $enrollmentId, int $position, array $config): array
    {
        $unit = $config['unit'] ?? null;
        $amount = $config['amount'] ?? null;

        if (! is_string($unit) || ! isset(AutomationDsl::DELAY_UNITS[$unit]) || ! is_int($amount) || $amount < 1) {
            return self::finish($tenantId, $enrollmentId, 'failed', 'The wait step is invalid.', $position);
        }

        self::update($tenantId, $enrollmentId, [
            'current_step' => $position,
            'next_run_at' => now()->addSeconds($amount * AutomationDsl::DELAY_UNITS[$unit]),
            'last_error' => null,
        ]);

        return ['outcome' => 'waiting', 'step' => $position];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{outcome: string, step: int|null}
     */
    private static function runSendEmail(int $tenantId, object $enrollment, int $position, array $config): array
    {
        $enrollmentId = (int) $enrollment->id;
        $current = $enrollment->current_step === null ? null : (int) $enrollment->current_step;

        // Unsubscribed or bounced since the enrollment (or since the last email of this sequence).
        if (EmailSuppressions::isSuppressed($tenantId, (string) $enrollment->email)) {
            return self::finish($tenantId, $enrollmentId, 'cancelled', 'Suppressed (unsubscribed, bounced or complained).', $current);
        }

        $templateId = $config['email_template_id'] ?? null;
        $template = is_int($templateId)
            ? DB::table('email_templates')->where('tenant_id', $tenantId)->where('id', $templateId)->first(['subject', 'body'])
            : null;

        if ($template === null) {
            return self::finish($tenantId, $enrollmentId, 'failed', 'The email template of this step no longer exists.', $current);
        }

        try {
            $sender = Schema::hasColumn('tenants', 'name')
                ? (string) DB::table('tenants')->where('id', $tenantId)->value('name')
                : '';

            $mail = SendRenderer::render(
                (string) $template->subject,
                (string) $template->body,
                TemplateVariables::valuesFor($enrollment->name, (string) $enrollment->email, $enrollment->job_title),
                EnrollmentUnsubscribeToken::url(EnrollmentUnsubscribeToken::make($tenantId, $enrollmentId)),
                $sender,
                null,
            );

            Mail::to((string) $enrollment->email)->send(
                new CampaignEmail($mail['subject'], $mail['html'], $mail['text'], $mail['headers']),
            );
        } catch (Throwable $e) {
            report($e);

            return self::finish($tenantId, $enrollmentId, 'failed', 'Send failed: '.mb_substr($e->getMessage(), 0, 400), $current);
        }

        self::update($tenantId, $enrollmentId, [
            'current_step' => $position,
            'next_run_at' => now(),
            'last_error' => null,
        ]);

        return ['outcome' => 'sent', 'step' => $position];
    }

    /** @return array{outcome: string, step: int|null} */
    private static function finish(int $tenantId, int $enrollmentId, string $status, string $error, ?int $step): array
    {
        self::update($tenantId, $enrollmentId, [
            'status' => $status,
            'next_run_at' => null,
            'last_error' => mb_substr($error, 0, 500),
        ]);

        return ['outcome' => $status, 'step' => $step];
    }

    /** @param array<string, mixed> $values */
    private static function update(int $tenantId, int $enrollmentId, array $values): void
    {
        DB::table('automation_enrollments')
            ->where('tenant_id', $tenantId)
            ->where('id', $enrollmentId)
            ->update($values + ['updated_at' => now()]);
    }
}