<?php

namespace App\Domain\Contacts\Import;

use Generator;
use InvalidArgumentException;

/**
 * Streams a contacts CSV one row at a time (never loads the whole file).
 * Tolerant of a UTF-8 BOM, Windows-1252 bytes, comma or semicolon delimiters,
 * common header spellings, and blank lines. Row numbers are the file's own
 * record numbers (header = 1) so error reports match what the user sees in Excel.
 */
final class ContactCsvReader
{
    public const MAX_ROWS = 5000;

    private const ALIASES = [
        'first_name' => ['first_name', 'firstname', 'first', 'given_name'],
        'last_name' => ['last_name', 'lastname', 'last', 'surname', 'family_name'],
        'email' => ['email', 'email_address', 'e_mail'],
        'phone' => ['phone', 'phone_number', 'mobile', 'telephone', 'tel'],
        'job_title' => ['job_title', 'jobtitle', 'title', 'position'],
    ];

    /**
     * Cheap synchronous pre-check used before queueing.
     *
     * @return int number of data rows
     *
     * @throws InvalidArgumentException with a user-facing message
     */
    public static function inspect(string $path): int
    {
        $total = 0;

        foreach (self::rows($path) as $unused) {
            $total++;

            if ($total > self::MAX_ROWS) {
                throw new InvalidArgumentException(
                    'The CSV has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files.'
                );
            }
        }

        if ($total === 0) {
            throw new InvalidArgumentException('The CSV has no data rows.');
        }

        return $total;
    }

    /**
     * @return Generator<int, array{0: int, 1: array<string, string|null>}> [rowNumber, fields]
     *
     * @throws InvalidArgumentException
     */
    public static function rows(string $path): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new InvalidArgumentException('The CSV file could not be read.');
        }

        try {
            $firstLine = fgets($handle);

            if ($firstLine === false) {
                throw new InvalidArgumentException('The CSV file is empty.');
            }

            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            rewind($handle);

            $header = fgetcsv($handle, null, $delimiter, '"', '');
            $map = self::mapHeader(is_array($header) ? $header : []);

            if (! isset($map['last_name'])) {
                throw new InvalidArgumentException('The CSV needs a "last_name" column (or "surname").');
            }

            $rowNumber = 1;

            while (($record = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $rowNumber++;

                if (self::isBlank($record)) {
                    continue;
                }

                $fields = [];
                foreach ($map as $field => $index) {
                    $value = $record[$index] ?? null;
                    $value = $value === null ? '' : trim(self::utf8($value));
                    $fields[$field] = $value === '' ? null : $value;
                }

                yield [$rowNumber, $fields];
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string|null> $header @return array<string, int> field => column index */
    private static function mapHeader(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $name = strtolower(trim(ltrim(self::utf8((string) $cell), "\xEF\xBB\xBF")));
            $name = trim((string) preg_replace('/[^a-z0-9]+/', '_', $name), '_');

            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($map[$field]) && in_array($name, $aliases, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    /** @param list<string|null> $record */
    private static function isBlank(array $record): bool
    {
        foreach ($record as $cell) {
            if ($cell !== null && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
