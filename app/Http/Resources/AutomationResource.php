<?php

namespace App\Http\Resources;

use App\Models\Automation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An automation as the SPA sees it (9.6). An explicit whitelist: no tenant id, no creator id. Trigger config and
 * step configs are rebuilt key by key because jsonb does not preserve key order.
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
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($step) => $this->step($step))->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
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