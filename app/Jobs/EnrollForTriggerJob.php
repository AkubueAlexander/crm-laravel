<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Marketing\Automations\AutomationEnroller;
use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * 9.6c-1: enrolls a freshly created contact or lead into the tenant's active automations for that trigger.
 * Idempotent (see AutomationEnroller), so retries are harmless.
 */
class EnrollForTriggerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $trigger,
        public readonly ?int $contactId = null,
        public readonly ?int $leadId = null,
    ) {
    }

    /**
     * The hook the create Actions call. It must never break the creation itself: one cheap query decides
     * whether anything is listening, the job is only queued after the surrounding transaction commits,
     * and any failure here is reported, not thrown.
     */
    public static function fire(int $tenantId, string $trigger, ?int $contactId, ?int $leadId): void
    {
        try {
            if (! AutomationEnroller::hasActiveFor($tenantId, $trigger)) {
                return;
            }

            self::dispatch($tenantId, $trigger, $contactId, $leadId)->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        TenantContext::set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            AutomationEnroller::enrollForTrigger($this->tenantId, $this->trigger, $this->contactId, $this->leadId);
        } finally {
            TenantContext::clear();
        }
    }
}