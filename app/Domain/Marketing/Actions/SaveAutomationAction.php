<?php

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Automations\AutomationDsl;
use App\Models\Automation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 9.6a: create and update share one write path. The whole definition (name, trigger, step list) is validated and
 * stored together; the step list is replaced under the automation's row lock, so two editors can never
 * interleave a half-written sequence. Status changes are NOT done here (TransitionAutomationAction, 9.6a-2).
 */
final class SaveAutomationAction
{
    /** @param array{name: string, description?: string|null, trigger_type: string, trigger_config?: array<string, mixed>|null, steps: array<int, mixed>} $data */
    public function create(User $actor, array $data): Automation
    {
        $tenantId = $this->authorize($actor);
        $this->validate($data, $tenantId);

        try {
            return DB::transaction(function () use ($actor, $data, $tenantId): Automation {
                // Tenant, status and creator come from the session, never from the request body (1.3).
                $automation = new Automation();
                $automation->forceFill(array_merge($this->definition($data), [
                    'tenant_id' => $tenantId,
                    'status' => Automation::STATUS_DRAFT,
                    'lock_version' => 0,
                    'created_by' => $actor->id,
                ]))->save();

                $this->replaceSteps((int) $automation->id, $tenantId, $data['steps']);

                return $automation->load('steps');
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    /** @param array{name: string, description?: string|null, trigger_type: string, trigger_config?: array<string, mixed>|null, steps: array<int, mixed>} $data */
    public function update(User $actor, int $automationId, array $data): Automation
    {
        $tenantId = $this->authorize($actor);
        $this->validate($data, $tenantId);

        try {
            return DB::transaction(function () use ($automationId, $data, $tenantId): Automation {
                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $automation = Automation::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($automationId);

                // Contacts may be mid-sequence on an active automation: pause it first.
                if ($automation->status === Automation::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'automation' => ['Pause this automation before editing it.'],
                    ]);
                }

                $automation->forceFill(array_merge($this->definition($data), [
                    // Any content change invalidates a client's copy, so a stale transition gets a 409.
                    'lock_version' => (int) $automation->lock_version + 1,
                ]))->save();

                $this->replaceSteps((int) $automation->id, $tenantId, $data['steps']);

                return $automation->load('steps');
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateName();
        }
    }

    private function authorize(User $actor): int
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('marketing.manage');

        return $tenantId;
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data, int $tenantId): void
    {
        $errors = [];

        $name = $data['name'] ?? null;

        if (! is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 160) {
            $errors['name'] = 'Enter a name of up to 160 characters.';
        }

        $description = $data['description'] ?? null;

        if ($description !== null && (! is_string($description) || mb_strlen($description) > 500)) {
            $errors['description'] = 'Keep the description to 500 characters or fewer.';
        }

        $errors += AutomationDsl::errors(
            $data['trigger_type'] ?? null,
            $data['trigger_config'] ?? null,
            $data['steps'] ?? null,
            $tenantId,
        );

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function definition(array $data): array
    {
        $description = isset($data['description']) ? trim((string) $data['description']) : '';

        return [
            'name' => trim((string) $data['name']),
            'description' => $description === '' ? null : $description,
            'trigger_type' => $data['trigger_type'],
            'trigger_config' => AutomationDsl::normalizeTriggerConfig($data['trigger_type'], $data['trigger_config'] ?? null),
        ];
    }

    /** @param array<int, mixed> $steps already validated */
    private function replaceSteps(int $automationId, int $tenantId, array $steps): void
    {
        DB::table('automation_steps')->where('tenant_id', $tenantId)->where('automation_id', $automationId)->delete();

        $now = now();
        $rows = [];

        foreach (AutomationDsl::normalizeSteps($steps) as $position => $step) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'automation_id' => $automationId,
                'position' => $position + 1,
                'type' => $step['type'],
                'config' => json_encode($step['config'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('automation_steps')->insert($rows);
    }

    private function duplicateName(): ValidationException
    {
        return ValidationException::withMessages(['name' => ['An automation with this name already exists.']]);
    }
}