<?php

namespace App\Http\Resources;

use App\Domain\Marketing\Automations\EnrollmentCounts;
use App\Models\Automation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An automation as the SPA sees it (9.6). An explicit whitelist: no tenant id, no creator id. Trigger config and
 * step configs are rebuilt key by key because jsonb does not preserve key order.
 *
 * 9.6c-3: `enrollments` carries the counts per status. The list endpoint preloads them for the whole page in one
 * query (attribute enrollment_counts); any other caller falls back to a single query.
 *
 * @mixin Automation
 */
class AutomationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $triggerConfig = [];

        if ($this->trigger_type === 'segment_entered') {
            $triggerConfig = ['segment_id' => isset($this->trigger_config['segment_id']) ? (int) $this->trigger_config['segment_id'] : null];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'trigger_type' => $this->trigger_type,
            // An empty PHP array would encode as [] : the contract is always an object.
            'trigger_config' => $triggerConfig === [] ? (object) [] : $triggerConfig,
            'lock_version' => (int) $this->lock_version,
            'enrollments' => $this->enrollments(),
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($step) => $this->step($step))->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{active: int, completed: int, cancelled: int, failed: int, total: int} */
    private function enrollments(): array
    {
        $counts = $this->resource->getAttribute('enrollment_counts');

        if (! is_array($counts)) {
            $counts = EnrollmentCounts::forAutomations((int) $this->tenant_id, [(int) $this->id])[(int) $this->id];
        }

        return [
            'active' => (int) ($counts['active'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'total' => (int) ($counts['total'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function step(mixed $step): array
    {
        $config = $step->config ?? [];

        return [
            'position' => (int) $step->position,
            'type' => $step->type,
            'config' => $step->type === 'delay'
                ? ['amount' => (int) ($config['amount'] ?? 0), 'unit' => (string) ($config['unit'] ?? '')]
                : ['email_template_id' => (int) ($config['email_template_id'] ?? 0)],
        ];
    }
}