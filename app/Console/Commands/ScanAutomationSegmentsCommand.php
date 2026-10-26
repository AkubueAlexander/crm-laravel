<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Marketing\Automations\AutomationEnroller;
use App\Domain\Marketing\Segments\SegmentQueryBuilder;
use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 9.6c-2: the "segment_entered" trigger. For every ACTIVE automation of that kind, enrolls the contacts that
 * match its segment and are not enrolled yet. The first scan after activation enrolls everyone who matches
 * today; later scans pick up contacts that newly match. Enrollment itself is idempotent (AutomationEnroller).
 */
final class ScanAutomationSegmentsCommand extends Command
{
    protected $signature = 'automations:scan-segments';

    protected $description = 'Enroll contacts that match the segment of an active segment_entered automation.';

    public function handle(): int
    {
        $automations = DB::table('automations')
            ->where('status', 'active')
            ->where('trigger_type', 'segment_entered')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'trigger_config']);

        $total = 0;

        foreach ($automations as $automation) {
            $tenantId = (int) $automation->tenant_id;
            $automationId = (int) $automation->id;

            $config = json_decode((string) $automation->trigger_config, true);
            $segmentId = is_array($config) ? (int) ($config['segment_id'] ?? 0) : 0;
            if ($segmentId < 1) {
                continue;
            }

            $segment = DB::table('segments')
                ->where('tenant_id', $tenantId)
                ->where('id', $segmentId)
                ->first(['match_mode', 'conditions']);
            if ($segment === null) {
                continue;
            }

            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                continue;
            }

            $conditions = json_decode((string) $segment->conditions, true);
            $conditions = is_array($conditions) ? $conditions : [];

            TenantContext::set($tenant);
            setPermissionsTeamId($tenant->id);

            try {
                SegmentQueryBuilder::contactsFor($tenantId, (string) $segment->match_mode, $conditions)
                    ->whereNotNull('contacts.email')
                    ->whereNotExists(function ($q) use ($automationId): void {
                        $q->select(DB::raw('1'))
                            ->from('automation_enrollments as ae')
                            ->whereColumn('ae.contact_id', 'contacts.id')
                            ->where('ae.automation_id', $automationId);
                    })
                    ->select('contacts.id')
                    ->chunkById(500, function ($contacts) use ($tenantId, $automationId, &$total): void {
                        $ids = array_map(static fn ($c) => (int) $c->id, $contacts->all());
                        $total += AutomationEnroller::enrollContacts($tenantId, $automationId, $ids)['enrolled'];
                    }, 'contacts.id', 'id');
            } finally {
                TenantContext::clear();
            }
        }

        $this->info("Enrolled {$total} contact(s) from segment triggers.");

        return self::SUCCESS;
    }
}