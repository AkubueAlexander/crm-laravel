<?php

namespace App\Domain\Marketing\Automations;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 9.6: the automation definition DSL: one trigger plus an ordered list of steps {type, config}.
 *
 * Same idea as SegmentDsl (9.2): everything allowed is whitelisted here, the catalog() drives the builder UI so
 * the SPA never hardcodes a trigger or a step type, and errors() never throws whatever the input looks like.
 * Ownership checks (segment, templates) run against the actor's own tenant only.
 */
final class AutomationDsl
{
    public const MAX_STEPS = 20;

    /** 90 days. */
    public const MAX_DELAY_SECONDS = 7776000;

    public const TRIGGERS = [
        'manual' => 'Enrolled manually',
        'contact_created' => 'A contact is created',
        'lead_created' => 'A lead is created',
        'segment_entered' => 'A contact matches a segment',
    ];

    public const STEP_TYPES = [
        'delay' => 'Wait',
        'send_email' => 'Send an email',
    ];

    /** unit => seconds */
    public const DELAY_UNITS = ['minutes' => 60, 'hours' => 3600, 'days' => 86400];

    /**
     * What the builder renders. kind: integer | select | segment | template (the last two are pickers fed by the
     * existing segment-options / template-options endpoints).
     *
     * @return array<string, mixed>
     */
    public static function catalog(): array
    {
        $triggers = [];

        foreach (self::TRIGGERS as $type => $label) {
            $triggers[] = [
                'type' => $type,
                'label' => $label,
                'params' => $type === 'segment_entered'
                    ? [['key' => 'segment_id', 'label' => 'Segment', 'kind' => 'segment']]
                    : [],
            ];
        }

        $units = [];

        foreach (array_keys(self::DELAY_UNITS) as $unit) {
            $units[] = ['value' => $unit, 'label' => ucfirst($unit)];
        }

        return [
            'triggers' => $triggers,
            'steps' => [
                [
                    'type' => 'delay',
                    'label' => self::STEP_TYPES['delay'],
                    'params' => [
                        ['key' => 'amount', 'label' => 'For', 'kind' => 'integer', 'min' => 1],
                        ['key' => 'unit', 'label' => 'Unit', 'kind' => 'select', 'options' => $units],
                    ],
                ],
                [
                    'type' => 'send_email',
                    'label' => self::STEP_TYPES['send_email'],
                    'params' => [['key' => 'email_template_id', 'label' => 'Email template', 'kind' => 'template']],
                ],
            ],
            'limits' => [
                'max_steps' => self::MAX_STEPS,
                'max_delay_days' => intdiv(self::MAX_DELAY_SECONDS, 86400),
            ],
        ];
    }

    /**
     * Validation messages keyed like Laravel validation errors (trigger_type, steps.2.config.amount, ...).
     * Empty array = valid.
     *
     * @return array<string, string>
     */
    public static function errors(mixed $triggerType, mixed $triggerConfig, mixed $steps, int $tenantId): array
    {
        $errors = [];

        if (! is_string($triggerType) || ! isset(self::TRIGGERS[$triggerType])) {
            $errors['trigger_type'] = 'Choose a trigger.';
        } elseif ($triggerType === 'segment_entered') {
            $config = is_array($triggerConfig) ? $triggerConfig : [];
            $segmentId = self::toId($config['segment_id'] ?? null);

            if ($segmentId === null || ! DB::table('segments')->where('tenant_id', $tenantId)->where('id', $segmentId)->exists()) {
                $errors['trigger_config.segment_id'] = 'Choose a segment.';
            }
        }

        if (! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            return $errors + ['steps' => 'Add at least one step.'];
        }

        if (count($steps) > self::MAX_STEPS) {
            return $errors + ['steps' => 'An automation can have at most '.self::MAX_STEPS.' steps.'];
        }

        $templateRefs = [];

        foreach ($steps as $i => $step) {
            $path = "steps.{$i}";

            if (! is_array($step)) {
                $errors[$path] = 'Invalid step.';

                continue;
            }

            $type = $step['type'] ?? null;

            if (! is_string($type) || ! isset(self::STEP_TYPES[$type])) {
                $errors["{$path}.type"] = 'Choose a step type.';

                continue;
            }

            $config = is_array($step['config'] ?? null) ? $step['config'] : [];

            if ($type === 'delay') {
                $unit = $config['unit'] ?? null;

                if (! is_string($unit) || ! isset(self::DELAY_UNITS[$unit])) {
                    $errors["{$path}.config.unit"] = 'Choose minutes, hours or days.';

                    continue;
                }

                $amount = self::toInt($config['amount'] ?? null);

                if ($amount === null || $amount < 1 || $amount * self::DELAY_UNITS[$unit] > self::MAX_DELAY_SECONDS) {
                    $errors["{$path}.config.amount"] = 'Enter a whole number of at least 1, up to 90 days in total.';
                }

                continue;
            }

            $templateId = self::toId($config['email_template_id'] ?? null);

            if ($templateId === null) {
                $errors["{$path}.config.email_template_id"] = 'Choose an email template.';
            } else {
                $templateRefs[$i] = $templateId;
            }
        }

        if ($templateRefs !== []) {
            $found = DB::table('email_templates')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', array_values(array_unique($templateRefs)))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($templateRefs as $i => $templateId) {
                if (! in_array($templateId, $found, true)) {
                    $errors["steps.{$i}.config.email_template_id"] = 'Choose an email template.';
                }
            }
        }

        $last = $steps[count($steps) - 1];

        if (is_array($last) && ($last['type'] ?? null) === 'delay') {
            $errors['steps'] = 'A sequence cannot end with a wait: finish with an email.';
        }

        return $errors;
    }

    /** 2.4: the Actions call this again regardless of what the request layer did. */
    public static function assertValid(mixed $triggerType, mixed $triggerConfig, mixed $steps, int $tenantId): void
    {
        $errors = self::errors($triggerType, $triggerConfig, $steps, $tenantId);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Canonical stored trigger config. Call only after errors() came back empty.
     *
     * @return array<string, int>
     */
    public static function normalizeTriggerConfig(string $triggerType, mixed $config): array
    {
        if ($triggerType === 'segment_entered') {
            return ['segment_id' => (int) self::toId(is_array($config) ? ($config['segment_id'] ?? null) : null)];
        }

        return [];
    }

    /**
     * Canonical stored steps: type plus a config reduced to the whitelisted keys, coerced. Call only after
     * errors() came back empty.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{type: string, config: array<string, int|string>}>
     */
    public static function normalizeSteps(array $steps): array
    {
        $out = [];

        foreach ($steps as $step) {
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];

            $out[] = $step['type'] === 'delay'
                ? ['type' => 'delay', 'config' => ['amount' => (int) self::toInt($config['amount'] ?? null), 'unit' => (string) $config['unit']]]
                : ['type' => 'send_email', 'config' => ['email_template_id' => (int) self::toId($config['email_template_id'] ?? null)]];
        }

        return $out;
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && strlen($value) <= 9) {
            return (int) $value;
        }

        return null;
    }

    private static function toId(mixed $value): ?int
    {
        $id = self::toInt($value);

        return $id !== null && $id >= 1 ? $id : null;
    }
}