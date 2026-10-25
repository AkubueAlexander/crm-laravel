<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Templates;

use App\Models\EmailTemplate;
use Illuminate\Validation\ValidationException;

/**
 * The single whitelist of {{ variables }} a template may use, plus the save-time validation
 * (2.4: Actions call assertValid again; the request/UI layers are only UX).
 */
final class TemplateVariables
{
    public const PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /** name => human description (the UI catalog renders from this). */
    public const VARIABLES = [
        'first_name' => 'First word of the contact name',
        'name' => 'Full contact name',
        'email' => 'Contact email address',
        'job_title' => 'Contact job title',
    ];

    /** Values used by the preview, never real contact data. */
    public const SAMPLE = [
        'first_name' => 'Ada',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'job_title' => 'Head of Engineering',
    ];

    /** @return list<array{name: string, label: string, sample: string}> */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::VARIABLES as $name => $label) {
            $out[] = ['name' => $name, 'label' => $label, 'sample' => self::SAMPLE[$name]];
        }

        return $out;
    }

    /** @return list<string> distinct variable names used in the text, in order of first use */
    public static function usedIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    public static function unknownIn(string $text): array
    {
        return array_values(array_diff(self::usedIn($text), array_keys(self::VARIABLES)));
    }

    /**
     * Build the value map for one contact (9.4 calls this per recipient).
     *
     * @return array<string, string>
     */
    public static function valuesFor(?string $name, ?string $email, ?string $jobTitle): array
    {
        $name = trim((string) $name);
        $first = $name === '' ? '' : (preg_split('/\s+/u', $name)[0] ?? '');

        return [
            'first_name' => $first,
            'name' => $name,
            'email' => trim((string) $email),
            'job_title' => trim((string) $jobTitle),
        ];
    }

    /** @return array<string, list<string>> errors keyed by subject|body, empty when valid */
    public static function errors(string $subject, string $body): array
    {
        $errors = [];

        $trimmedSubject = trim($subject);
        if ($trimmedSubject === '') {
            $errors['subject'][] = 'Subject is required.';
        } elseif (mb_strlen($trimmedSubject) > EmailTemplate::MAX_SUBJECT) {
            $errors['subject'][] = 'Subject must be at most '.EmailTemplate::MAX_SUBJECT.' characters.';
        }
        if (preg_match('/[\r\n]/', $subject) === 1) {
            $errors['subject'][] = 'Subject must be a single line.';
        }

        if (trim($body) === '') {
            $errors['body'][] = 'Body is required.';
        } elseif (mb_strlen($body) > EmailTemplate::MAX_BODY) {
            $errors['body'][] = 'Body must be at most '.EmailTemplate::MAX_BODY.' characters.';
        }

        foreach (['subject' => $subject, 'body' => $body] as $key => $text) {
            $unknown = self::unknownIn($text);
            if ($unknown !== []) {
                $errors[$key][] = 'Unknown variable(s): '
                    .implode(', ', array_map(static fn (string $n): string => '{{ '.$n.' }}', $unknown)).'.';
            }
        }

        return $errors;
    }

    public static function assertValid(string $subject, string $body): void
    {
        $errors = self::errors($subject, $body);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}