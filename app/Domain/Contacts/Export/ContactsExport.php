<?php

namespace App\Domain\Contacts\Export;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * 8b.4: what an exported contacts sheet contains. The query is built by queryFor() so the
 * request-time row count and the queued job always agree. Deliberately NOT ShouldQueue:
 * the job that runs it is already the queued unit.
 */
// Extends StringValueBinder so every cell is written as text. The default binder turns
// numeric-looking strings ("+2348012345678", "2348012345678") into numbers, which drops
// the "+" in CSV and shows 2.35E+12 in Excel, and it would also evaluate "=..." as a formula.
class ContactsExport extends StringValueBinder implements FromQuery, WithCustomCsvSettings, WithCustomValueBinder, WithHeadings, WithMapping
{
    /** Mirrors the sortable columns of the contacts list (frontend CONTACT_SORTABLE_COLUMNS). */
    public const SORTABLE = ['last_name', 'first_name', 'email', 'created_at'];

    public function __construct(private readonly Builder $query)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{q: string, sort: string, direction: string}
     */
    public static function normalizeFilters(array $input): array
    {
        $sort = (string) ($input['sort'] ?? 'last_name');

        return [
            'q' => trim((string) ($input['q'] ?? '')),
            'sort' => in_array($sort, self::SORTABLE, true) ? $sort : 'last_name',
            'direction' => ($input['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * Explicit tenant predicate: queue workers are console processes, where the
     * BelongsToTenant global scope is skipped.
     *
     * @param array<string, mixed> $filters
     */
    public static function queryFor(int $tenantId, array $filters): Builder
    {
        $f = self::normalizeFilters($filters);

        return Contact::query()
            ->where('tenant_id', $tenantId)
            ->matchingSearch($f['q'])
            ->orderBy($f['sort'], $f['direction'])
            ->orderBy('id'); // stable tiebreaker: chunked reads must never skip or repeat a row
    }

    public function query(): Builder
    {
        return $this->query;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['Last name', 'First name', 'Email', 'Phone', 'Job title', 'Created at'];
    }

    /**
     * @param Contact $row
     * @return list<string|null>
     */
    public function map($row): array
    {
        return [
            self::safe($row->last_name),
            self::safe($row->first_name),
            self::safe($row->email),
            self::safe($row->phone, true),
            self::safe($row->job_title),
            $row->created_at?->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    public function getCsvSettings(): array
    {
        // A BOM makes Excel read non-ASCII names as UTF-8.
        return ['use_bom' => true];
    }

    /**
     * Spreadsheet formula injection: a cell that starts with = + - @ is executed by Excel.
     * Contact data comes from users and CSV imports, so such cells get a leading apostrophe.
     * A plain international phone number (+234 801 ...) cannot execute anything and is kept as is.
     */
    public static function safe(?string $value, bool $isPhone = false): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! preg_match('/^\s*[=+\-@]/', $value)) {
            return $value;
        }

        if ($isPhone && preg_match('/^\+[0-9\s().\-]+$/', $value)) {
            return $value;
        }

        return "'".$value;
    }
}
