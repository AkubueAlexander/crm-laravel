<?php

namespace App\Domain\Marketing\Segments;

use App\Models\Segment;
use Illuminate\Validation\ValidationException;

/**
 * 9.2: the segment filter DSL. A condition is {field, operator, value}.
 *
 * Everything the DSL can express is whitelisted here, in one place: the field catalog (which also drives
 * the builder UI), the operators each field type allows, and the shape of each operator's value. The query
 * builder only ever sees conditions that passed errors(), and it maps them to fixed SQL expressions with
 * bound values: user input never becomes SQL text.
 */
final class SegmentDsl
{
    public const MAX_CONDITIONS = 20;

    public const MAX_TEXT_LENGTH = 100;

    public const MAX_DAYS = 3650;

    /** operator => label + the kind of value it takes (none | string | date | days). */
    public const OPERATORS = [
        'contains' => ['label' => 'contains', 'kind' => 'string'],
        'not_contains' => ['label' => 'does not contain', 'kind' => 'string'],
        'equals' => ['label' => 'is', 'kind' => 'string'],
        'not_equals' => ['label' => 'is not', 'kind' => 'string'],
        'starts_with' => ['label' => 'starts with', 'kind' => 'string'],
        'is_empty' => ['label' => 'is empty', 'kind' => 'none'],
        'is_not_empty' => ['label' => 'is not empty', 'kind' => 'none'],
        'before' => ['label' => 'is before', 'kind' => 'date'],
        'after' => ['label' => 'is after', 'kind' => 'date'],
        'in_last_days' => ['label' => 'was in the last N days', 'kind' => 'days'],
    ];

    /** @var array<string, list<string>> */
    private const OPERATORS_BY_TYPE = [
        'text' => ['contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'is_empty', 'is_not_empty'],
        'presence' => ['is_empty', 'is_not_empty'],
        'date' => ['before', 'after', 'in_last_days'],
    ];

    /**
     * field => label, type, and the trusted SQL expression over the contacts table. These strings are constants
     * in code and are never built from input. Text expressions are already lower-cased.
     *
     * @var array<string, array{label: string, type: string, expr: string}>
     */
    private const FIELDS = [
        'name' => ['label' => 'Name', 'type' => 'text', 'expr' => 'contacts.name_normalized'],
        'email' => ['label' => 'Email', 'type' => 'text', 'expr' => 'contacts.email_normalized'],
        'job_title' => ['label' => 'Job title', 'type' => 'text', 'expr' => 'lower(contacts.job_title)'],
        // "Has a usable phone number": phone_key is null when the number has fewer than 7 digits.
        'phone' => ['label' => 'Phone number', 'type' => 'presence', 'expr' => 'contacts.phone_key'],
        'account' => ['label' => 'Account', 'type' => 'presence', 'expr' => 'contacts.account_id'],
        'created_at' => ['label' => 'Created', 'type' => 'date', 'expr' => 'contacts.created_at'],
    ];

    /** @return array{label: string, type: string, expr: string} */
    public static function definition(string $field): array
    {
        return self::FIELDS[$field];
    }

    /** @return list<string> */
    public static function operatorsFor(string $field): array
    {
        return self::OPERATORS_BY_TYPE[self::FIELDS[$field]['type']];
    }

    public static function valueKind(string $operator): string
    {
        return self::OPERATORS[$operator]['kind'];
    }

    /**
     * The catalog the builder UI renders, so the frontend never hardcodes a field or an operator.
     *
     * @return list<array{field: string, label: string, type: string, operators: list<array{operator: string, label: string, value_kind: string}>}>
     */
    public static function fields(): array
    {
        $out = [];

        foreach (self::FIELDS as $field => $definition) {
            $operators = [];

            foreach (self::operatorsFor($field) as $operator) {
                $operators[] = [
                    'operator' => $operator,
                    'label' => self::OPERATORS[$operator]['label'],
                    'value_kind' => self::OPERATORS[$operator]['kind'],
                ];
            }

            $out[] = ['field' => $field, 'label' => $definition['label'], 'type' => $definition['type'], 'operators' => $operators];
        }

        return $out;
    }

    /**
     * Validation messages keyed like Laravel validation errors (conditions, conditions.0.field, ...).
     * Empty array = valid. Never throws, whatever the input looks like.
     *
     * @return array<string, string>
     */
    public static function errors(mixed $conditions): array
    {
        if (! is_array($conditions) || ! array_is_list($conditions) || $conditions === []) {
            return ['conditions' => 'Add at least one condition.'];
        }

        if (count($conditions) > self::MAX_CONDITIONS) {
            return ['conditions' => 'A segment can have at most '.self::MAX_CONDITIONS.' conditions.'];
        }

        $errors = [];

        foreach ($conditions as $i => $condition) {
            $path = "conditions.{$i}";

            if (! is_array($condition)) {
                $errors[$path] = 'Invalid condition.';

                continue;
            }

            $field = $condition['field'] ?? null;

            if (! is_string($field) || ! isset(self::FIELDS[$field])) {
                $errors["{$path}.field"] = 'Choose a field.';

                continue;
            }

            $operator = $condition['operator'] ?? null;

            if (! is_string($operator) || ! in_array($operator, self::operatorsFor($field), true)) {
                $errors["{$path}.operator"] = 'Choose a valid operator for this field.';

                continue;
            }

            $problem = self::valueError(self::OPERATORS[$operator]['kind'], $condition['value'] ?? null);

            if ($problem !== null) {
                $errors["{$path}.value"] = $problem;
            }
        }

        return $errors;
    }

    /** 2.4: the Actions call this again regardless of what the request layer did. */
    public static function assertValid(mixed $matchMode, mixed $conditions): void
    {
        $errors = self::errors($conditions);

        if (! is_string($matchMode) || ! in_array($matchMode, Segment::MATCH_MODES, true)) {
            $errors = ['match_mode' => 'Choose whether all or any condition must match.'] + $errors;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The canonical stored form: only field, operator and value, in that order, with the value coerced to its
     * kind. Call only with conditions that passed errors().
     *
     * @param  list<array<string, mixed>>  $conditions
     * @return list<array{field: string, operator: string, value: string|int|null}>
     */
    public static function normalize(array $conditions): array
    {
        $out = [];

        foreach ($conditions as $condition) {
            $kind = self::OPERATORS[$condition['operator']]['kind'];

            $out[] = [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => match ($kind) {
                    'string' => trim((string) $condition['value']),
                    'date' => (string) $condition['value'],
                    'days' => self::toDays($condition['value']),
                    default => null,
                },
            ];
        }

        return $out;
    }

    private static function valueError(string $kind, mixed $value): ?string
    {
        return match ($kind) {
            'string' => self::stringError($value),
            'date' => self::dateError($value),
            'days' => self::daysError($value),
            default => null,
        };
    }

    private static function stringError(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Enter a value.';
        }

        if (mb_strlen(trim($value)) > self::MAX_TEXT_LENGTH) {
            return 'Keep it to '.self::MAX_TEXT_LENGTH.' characters or fewer.';
        }

        return null;
    }

    private static function dateError(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return 'Enter a valid date (YYYY-MM-DD).';
    }

    private static function daysError(mixed $value): ?string
    {
        $days = self::toDays($value);

        if ($days === null || $days < 1 || $days > self::MAX_DAYS) {
            return 'Enter a whole number of days between 1 and '.self::MAX_DAYS.'.';
        }

        return null;
    }

    private static function toDays(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && strlen($value) <= 6) {
            return (int) $value;
        }

        return null;
    }
}
