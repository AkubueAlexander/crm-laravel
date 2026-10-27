<?php

namespace App\Domain\Leads\Forms;

/**
 * 9.8: what a lead-capture form may contain. Only these lead fields can ever be collected from the public, so a
 * visitor can never write status, owner, score or any other column. The same whitelist drives the builder UI,
 * the stored definition and the rules the public submit endpoint validates with.
 */
final class LeadFormDsl
{
    public const MAX_NAME = 160;

    public const MAX_LABEL = 60;

    public const MAX_SOURCE = 100;

    public const MAX_MESSAGE = 300;

    /** key => default label. */
    public const FIELDS = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'phone' => 'Phone',
        'company' => 'Company',
    ];

    /** @return list<array{key: string, label: string}> */
    public static function catalog(): array
    {
        $out = [];

        foreach (self::FIELDS as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }

    /**
     * Validation messages keyed like Laravel errors (fields, fields.0.key, ...). Empty = valid. Never throws.
     *
     * @return array<string, string>
     */
    public static function errors(mixed $fields): array
    {
        if (! is_array($fields) || ! array_is_list($fields) || $fields === []) {
            return ['fields' => 'Add at least one field.'];
        }

        if (count($fields) > count(self::FIELDS)) {
            return ['fields' => 'A form can have at most '.count(self::FIELDS).' fields.'];
        }

        $errors = [];
        $seen = [];

        foreach ($fields as $i => $field) {
            $path = "fields.{$i}";

            if (! is_array($field)) {
                $errors[$path] = 'Invalid field.';

                continue;
            }

            $key = $field['key'] ?? null;

            if (! is_string($key) || ! isset(self::FIELDS[$key])) {
                $errors["{$path}.key"] = 'Choose a field.';

                continue;
            }

            if (array_key_exists($key, $seen)) {
                $errors["{$path}.key"] = 'This field is already on the form.';

                continue;
            }

            $required = $field['required'] ?? false;

            if (! is_bool($required)) {
                $errors["{$path}.required"] = 'Required must be true or false.';

                continue;
            }

            $seen[$key] = $required;

            $label = $field['label'] ?? null;

            if (! is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > self::MAX_LABEL) {
                $errors["{$path}.label"] = 'Enter a label of up to '.self::MAX_LABEL.' characters.';
            }
        }

        if ($errors === []) {
            if (($seen['first_name'] ?? false) !== true) {
                $errors['fields'] = 'First name must be on the form and required.';
            } elseif (! array_key_exists('email', $seen) && ! array_key_exists('phone', $seen)) {
                $errors['fields'] = 'Add an email or phone field so you can reach the lead.';
            }
        }

        return $errors;
    }

    /**
     * The canonical stored form. Call only with fields that passed errors().
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array{key: string, label: string, required: bool}>
     */
    public static function normalize(array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $out[] = [
                'key' => (string) $field['key'],
                'label' => trim((string) $field['label']),
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Rules for the public submit (9.8a-2), mirroring StoreLeadRequest for the same columns.
     *
     * @param  list<array{key: string, label: string, required: bool}>  $fields
     * @return array<string, list<string>>
     */
    public static function submissionRules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $presence = $field['required'] ? 'required' : 'nullable';

            $rules[$field['key']] = match ($field['key']) {
                'first_name', 'last_name' => [$presence, 'string', 'max:100'],
                'email' => [$presence, 'string', 'email', 'max:255'],
                'phone' => [$presence, 'string', 'max:50', 'regex:/^[\d\s+().\-x#]+$/i'],
                'company' => [$presence, 'string', 'max:255'],
            };
        }

        return $rules;
    }
}