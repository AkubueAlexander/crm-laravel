<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Automations\AutomationDsl;
use App\Domain\Marketing\Exceptions\AutomationTransitionException;
use App\Models\Automation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * 9.6a-2: the single entry point for automation status changes (the 3.0 pattern: expected lock_version,
 * row lock, one allowed-transition map). Activating re-validates the stored definition, because a template
 * or segment it points at may have been deleted since it was saved.
 */
final class TransitionAutomationAction
{
    public const TRANSITIONS = [
        'draft' => ['active'],
        'active' => ['paused'],
        'paused' => ['active', 'draft'],
    ];

    public const USER_TARGETS = ['draft', 'active', 'paused'];

    public function execute(User $actor, int $automationId, string $toStatus, int $expectedLockVersion): Automation
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('marketing.manage');

        return DB::transaction(function () use ($automationId, $toStatus, $expectedLockVersion, $tenantId): Automation {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $automation = Automation::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($automationId);

            if ((int) $automation->lock_version !== $expectedLockVersion) {
                throw AutomationTransitionException::stale($automation->load('steps'));
            }

            $from = (string) $automation->status;

            if (! in_array($toStatus, self::USER_TARGETS, true) || ! in_array($toStatus, self::TRANSITIONS[$from] ?? [], true)) {
                throw AutomationTransitionException::invalid($from, $toStatus);
            }

            if ($toStatus === Automation::STATUS_ACTIVE) {
                $this->assertRunnable($automation, $tenantId);
            }

            $automation->forceFill([
                'status' => $toStatus,
                'lock_version' => (int) $automation->lock_version + 1,
            ])->save();

            return $automation->load('steps');
        });
    }

    /** Needs at least one step and a definition that is still valid; referenced rows stay locked until commit. */
    private function assertRunnable(Automation $automation, int $tenantId): void
    {
        $steps = DB::table('automation_steps')
            ->where('tenant_id', $tenantId)
            ->where('automation_id', $automation->id)
            ->orderBy('position')
            ->get()
            ->map(fn ($row) => ['type' => $row->type, 'config' => json_decode((string) $row->config, true) ?: []])
            ->all();

        $errors = AutomationDsl::errors($automation->trigger_type, $automation->trigger_config ?? [], $steps, $tenantId);

        if ($errors !== []) {
            throw AutomationTransitionException::definitionInvalid((string) reset($errors));
        }

        // A concurrent delete of a referenced template or segment either finishes first (we refused above)
        // or waits on these locks.
        $templateIds = [];

        foreach ($steps as $step) {
            if ($step['type'] === 'send_email') {
                $templateIds[] = (int) $step['config']['email_template_id'];
            }
        }

        if ($templateIds !== []) {
            DB::table('email_templates')->where('tenant_id', $tenantId)->whereIn('id', array_unique($templateIds))->lockForUpdate()->pluck('id');
        }

        if ($automation->trigger_type === 'segment_entered') {
            DB::table('segments')->where('tenant_id', $tenantId)->where('id', (int) ($automation->trigger_config['segment_id'] ?? 0))->lockForUpdate()->value('id');
        }
    }
}