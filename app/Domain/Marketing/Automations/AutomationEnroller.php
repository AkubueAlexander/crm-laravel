<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Automations;

use App\Domain\Marketing\Sending\EmailSuppressions;
use Illuminate\Support\Facades\DB;

/**
 * 9.6c-1: puts people into an ACTIVE automation. Plain DB access with explicit tenant predicates (safe from
 * queue jobs, commands and controllers alike). Idempotent: the unique indexes on automation_enrollments
 * decide, via insertOrIgnore, so a retried job or a double click can never enroll anyone twice.
 * Suppressed (unsubscribed, bounced, complained) and invalid addresses are never enrolled.
 */
final class AutomationEnroller
{
    /** @return array{enrolled: int, already_enrolled: int, skipped_suppressed: int, skipped_invalid: int} */
    public static function emptyCounts(): array
    {
        return ['enrolled' => 0, 'already_enrolled' => 0, 'skipped_suppressed' => 0, 'skipped_invalid' => 0];
    }

    public static function hasActiveFor(int $tenantId, string $triggerType): bool
    {
        return DB::table('automations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->exists();
    }

    /**
     * @param  list<int>  $contactIds  ids of other tenants' (or deleted) contacts are silently ignored
     * @return array{enrolled: int, already_enrolled: int, skipped_suppressed: int, skipped_invalid: int}
     */
    public static function enrollContacts(int $tenantId, int $automationId, array $contactIds): array
    {
        $counts = self::emptyCounts();
        if (! self::isActive($tenantId, $automationId)) {
            return $counts;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $contactIds),
            static fn (int $id): bool => $id > 0,
        )));

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('contacts')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereIn('id', $chunk)
                ->orderBy('id')
                ->get(['id', 'first_name', 'last_name', 'email', 'job_title']);

            $candidates = [];
            foreach ($rows as $row) {
                $candidates[] = [
                    'contact_id' => (int) $row->id,
                    'lead_id' => null,
                    'email' => (string) $row->email,
                    'name' => trim(((string) $row->first_name).' '.((string) $row->last_name)),
                    'job_title' => $row->job_title === null ? null : trim((string) $row->job_title),
                ];
            }

            self::enrollCandidates($tenantId, $automationId, $candidates, $counts);
        }

        return $counts;
    }

    /** @return array{enrolled: int, already_enrolled: int, skipped_suppressed: int, skipped_invalid: int} */
    public static function enrollLead(int $tenantId, int $automationId, int $leadId): array
    {
        $counts = self::emptyCounts();
        if (! self::isActive($tenantId, $automationId)) {
            return $counts;
        }

        $lead = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('id', $leadId)
            ->first(['id', 'first_name', 'last_name', 'email']);

        if ($lead === null) {
            return $counts;
        }

        self::enrollCandidates($tenantId, $automationId, [[
            'contact_id' => null,
            'lead_id' => (int) $lead->id,
            'email' => (string) $lead->email,
            'name' => trim(((string) $lead->first_name).' '.((string) $lead->last_name)),
            'job_title' => null,
        ]], $counts);

        return $counts;
    }

    /** Enrolls into every ACTIVE automation of the tenant with this trigger. Returns how many enrollments were created. */
    public static function enrollForTrigger(int $tenantId, string $triggerType, ?int $contactId, ?int $leadId): int
    {
        $automationIds = DB::table('automations')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->orderBy('id')
            ->pluck('id');

        $enrolled = 0;
        foreach ($automationIds as $automationId) {
            if ($triggerType === 'contact_created' && $contactId !== null) {
                $counts = self::enrollContacts($tenantId, (int) $automationId, [$contactId]);
            } elseif ($triggerType === 'lead_created' && $leadId !== null) {
                $counts = self::enrollLead($tenantId, (int) $automationId, $leadId);
            } else {
                continue;
            }
            $enrolled += $counts['enrolled'];
        }

        return $enrolled;
    }

    private static function isActive(int $tenantId, int $automationId): bool
    {
        return DB::table('automations')
            ->where('tenant_id', $tenantId)
            ->where('id', $automationId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @param  list<array{contact_id: int|null, lead_id: int|null, email: string, name: string, job_title: string|null}>  $candidates
     * @param  array{enrolled: int, already_enrolled: int, skipped_suppressed: int, skipped_invalid: int}  $counts
     */
    private static function enrollCandidates(int $tenantId, int $automationId, array $candidates, array &$counts): void
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $email = trim($candidate['email']);
            $normalized = EmailSuppressions::normalize($email);

            if ($normalized === '' || strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
                $counts['skipped_invalid']++;

                continue;
            }
            // The same address twice in one batch is one person.
            if (isset($unique[$normalized])) {
                $counts['already_enrolled']++;

                continue;
            }
            $unique[$normalized] = $candidate + ['clean_email' => $email];
        }

        if ($unique === []) {
            return;
        }

        $suppressed = EmailSuppressions::suppressedAmong($tenantId, array_map('strval', array_keys($unique)));

        foreach ($unique as $normalized => $candidate) {
            $normalized = (string) $normalized;
            if (isset($suppressed[$normalized])) {
                $counts['skipped_suppressed']++;

                continue;
            }

            $inserted = DB::table('automation_enrollments')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'automation_id' => $automationId,
                'contact_id' => $candidate['contact_id'],
                'lead_id' => $candidate['lead_id'],
                'email' => $candidate['clean_email'],
                'email_normalized' => $normalized,
                'name' => $candidate['name'] === '' ? null : $candidate['name'],
                'job_title' => $candidate['job_title'] === '' ? null : $candidate['job_title'],
                'status' => 'active',
                'current_step' => null,
                'next_run_at' => now(),
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted > 0) {
                $counts['enrolled']++;
            } else {
                $counts['already_enrolled']++;
            }
        }
    }
}