$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw 'artisan not found: wrong repo path' }
Set-Location $repo

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = Split-Path $Path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, ($Content -replace "`r`n", "`n"), $enc)
}

function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $raw = [System.IO.File]::ReadAllText($Path)
    $crlf = $raw.Contains("`r`n")
    $text = $raw -replace "`r`n", "`n"
    if ($text.Contains($Skip)) { return 'skipped' }
    $a = $Anchor -replace "`r`n", "`n"
    $r = $Replacement -replace "`r`n", "`n"
    if ((Count-Sub $text $a) -ne 1) { throw "Anchor not exactly once in $Path : $Anchor" }
    $idx = $text.IndexOf($a, [System.StringComparison]::Ordinal)
    $text = $text.Substring(0, $idx) + $r + $text.Substring($idx + $a.Length)
    if ($crlf) { $text = $text -replace "`n", "`r`n" }
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $text, $enc)
    return 'patched'
}

$dsl = 'app\Domain\Marketing\Segments\SegmentDsl.php'
$qb = 'app\Domain\Marketing\Segments\SegmentQueryBuilder.php'
$form = 'frontend\src\features\marketing\schemas\segmentForm.ts'
$row = 'frontend\src\features\marketing\components\ConditionRow.tsx'
foreach ($p in @($dsl, $qb, $form, $row)) { if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing $p" } }

$patches = New-Object System.Collections.Generic.List[object]

# ---- SegmentDsl
$patches.Add(@{ Path = $dsl; Skip = 'MAX_SCORE = 100'
Anchor = 'public const MAX_DAYS = 3650;'
Replacement = @'
public const MAX_DAYS = 3650;

    public const MAX_SCORE = 100;
'@ })

$patches.Add(@{ Path = $dsl; Skip = "'num_equals' => ['label'"
Anchor = "'in_last_days' => ['label' => 'was in the last N days', 'kind' => 'days'],"
Replacement = @'
'in_last_days' => ['label' => 'was in the last N days', 'kind' => 'days'],
        'gte' => ['label' => 'is at least', 'kind' => 'number'],
        'lte' => ['label' => 'is at most', 'kind' => 'number'],
        'num_equals' => ['label' => 'is exactly', 'kind' => 'number'],
'@ })

$patches.Add(@{ Path = $dsl; Skip = "'number' => ['gte'"
Anchor = "'date' => ['before', 'after', 'in_last_days'],"
Replacement = @'
'date' => ['before', 'after', 'in_last_days'],
        'number' => ['gte', 'lte', 'num_equals', 'is_empty', 'is_not_empty'],
'@ })

$patches.Add(@{ Path = $dsl; Skip = "'lead_score' => ['label'"
Anchor = "'created_at' => ['label' => 'Created', 'type' => 'date', 'expr' => 'contacts.created_at'],"
Replacement = @'
'created_at' => ['label' => 'Created', 'type' => 'date', 'expr' => 'contacts.created_at'],
        // 9.7: highest score among the (non-deleted) leads this contact was converted from; null when none.
        'lead_score' => ['label' => 'Lead score', 'type' => 'number', 'expr' => '(select max(l.lead_score) from leads l where l.converted_contact_id = contacts.id and l.tenant_id = contacts.tenant_id and l.deleted_at is null)'],
'@ })

$patches.Add(@{ Path = $dsl; Skip = 'self::toScore($condition'
Anchor = "'days' => self::toDays(`$condition['value']),"
Replacement = @'
'days' => self::toDays($condition['value']),
                    'number' => self::toScore($condition['value']),
'@ })

$patches.Add(@{ Path = $dsl; Skip = 'self::scoreError('
Anchor = "'days' => self::daysError(`$value),"
Replacement = @'
'days' => self::daysError($value),
            'number' => self::scoreError($value),
'@ })

$patches.Add(@{ Path = $dsl; Skip = 'function scoreError'
Anchor = 'private static function toDays(mixed $value): ?int'
Replacement = @'
private static function scoreError(mixed $value): ?string
    {
        $score = self::toScore($value);

        if ($score === null || $score < 0 || $score > self::MAX_SCORE) {
            return 'Enter a whole number between 0 and '.self::MAX_SCORE.'.';
        }

        return null;
    }

    private static function toScore(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && strlen($value) <= 3) {
            return (int) $value;
        }

        return null;
    }

    private static function toDays(mixed $value): ?int
'@ })

# ---- SegmentQueryBuilder
$patches.Add(@{ Path = $qb; Skip = "['presence', 'number']"
Anchor = "`$presence = `$definition['type'] === 'presence';"
Replacement = @'
$presence = in_array($definition['type'], ['presence', 'number'], true);
'@ })

$patches.Add(@{ Path = $qb; Skip = "'num_equals' =>"
Anchor = "'in_last_days' => [`"{`$expr} >= ?`", [now()->subDays((int) `$value)->toDateTimeString()]],"
Replacement = @'
'in_last_days' => ["{$expr} >= ?", [now()->subDays((int) $value)->toDateTimeString()]],
            'gte' => ["{$expr} >= ?", [(int) $value]],
            'lte' => ["{$expr} <= ?", [(int) $value]],
            'num_equals' => ["{$expr} = ?", [(int) $value]],
'@ })

$patches.Add(@{ Path = $qb; Skip = 'list<string|int>}'
Anchor = '* @return array{0: string, 1: list<string>}'
Replacement = @'
* @return array{0: string, 1: list<string|int>}
'@ })

# ---- Frontend
$patches.Add(@{ Path = $form; Skip = "'days', 'number'"
Anchor = "export const valueKindSchema = z.enum(['string', 'none', 'date', 'days']);"
Replacement = @'
export const valueKindSchema = z.enum(['string', 'none', 'date', 'days', 'number']);
'@ })

$patches.Add(@{ Path = $form; Skip = "case 'number'"
Anchor = "case 'days': {"
Replacement = @'
case 'number': {
      if (!/^\d+$/.test(value)) return 'Enter a whole number';
      if (value.length > 3 || Number(value) > 100) return 'Use a number between 0 and 100';
      return null;
    }
    case 'days': {
'@ })

$patches.Add(@{ Path = $form; Skip = "|| kind === 'number'"
Anchor = "if (kind === 'days') return Number(value);"
Replacement = @'
if (kind === 'days' || kind === 'number') return Number(value);
'@ })

$patches.Add(@{ Path = $row; Skip = "kind === 'number' ? ("
Anchor = ") : kind === 'days' ? ("
Replacement = @'
) : kind === 'number' ? (
            <input
              key="number"
              type="number"
              inputMode="numeric"
              min={0}
              max={100}
              aria-label={`Condition ${index + 1} value`}
              className={controlClass}
              {...valueReg}
            />
          ) : kind === 'days' ? (
'@ })

# PREFLIGHT
foreach ($p in $patches) {
    $full = Join-Path $repo $p.Path
    $t = ([System.IO.File]::ReadAllText($full)) -replace "`r`n", "`n"
    if (-not $t.Contains($p.Skip)) {
        $a = $p.Anchor -replace "`r`n", "`n"
        if ((Count-Sub $t $a) -ne 1) { throw "PREFLIGHT: anchor not exactly once in $($p.Path): $($p.Anchor)" }
    }
}

Write-NoBom 'tests\Feature\Marketing\SegmentLeadScoreTest.php' @'
<?php

use App\Domain\Marketing\Segments\SegmentDsl;
use App\Domain\Marketing\Segments\SegmentQueryBuilder;
use App\Domain\Tenant\TenantContext;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

function segscoreTenant(): Tenant
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);

    return $tenant;
}

function segscoreContact(Tenant $tenant): Contact
{
    TenantContext::set($tenant);

    return Contact::factory()->create(['tenant_id' => $tenant->id]);
}

/** Creates a lead in $leadTenant pointing at $contact, then sets score (and deleted_at) with plain SQL. */
function segscoreLead(Tenant $leadTenant, Contact $contact, int $score, bool $deleted = false): void
{
    TenantContext::set($leadTenant);
    $lead = Lead::factory()->create(['tenant_id' => $leadTenant->id]);

    DB::table('leads')->where('id', $lead->id)->update([
        'converted_contact_id' => $contact->id,
        'lead_score' => $score,
        'deleted_at' => $deleted ? now() : null,
    ]);
}

/** @return list<int> */
function segscoreIds(Tenant $tenant, string $operator, mixed $value = null): array
{
    TenantContext::set($tenant);

    return SegmentQueryBuilder::contactsFor($tenant->id, 'all', [
        ['field' => 'lead_score', 'operator' => $operator, 'value' => $value],
    ])->pluck('contacts.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

test('catalog exposes lead score as a number field', function () {
    $field = collect(SegmentDsl::fields())->firstWhere('field', 'lead_score');

    expect($field)->not->toBeNull()
        ->and($field['type'])->toBe('number');

    $kinds = collect($field['operators'])->pluck('value_kind', 'operator')->all();

    expect($kinds)->toEqual([
        'gte' => 'number',
        'lte' => 'number',
        'num_equals' => 'number',
        'is_empty' => 'none',
        'is_not_empty' => 'none',
    ]);
});

test('dsl validates and normalizes lead score values', function () {
    $ok = fn ($op, $value) => SegmentDsl::errors([['field' => 'lead_score', 'operator' => $op, 'value' => $value]]);

    expect($ok('gte', 50))->toBe([])
        ->and($ok('lte', '50'))->toBe([])
        ->and($ok('num_equals', 0))->toBe([])
        ->and($ok('is_empty', null))->toBe([])
        ->and(array_keys($ok('gte', 101)))->toBe(['conditions.0.value'])
        ->and(array_keys($ok('gte', -1)))->toBe(['conditions.0.value'])
        ->and(array_keys($ok('gte', 'abc')))->toBe(['conditions.0.value'])
        ->and(array_keys($ok('gte', null)))->toBe(['conditions.0.value'])
        ->and(array_keys($ok('contains', 'x')))->toBe(['conditions.0.operator']);

    $normalized = SegmentDsl::normalize([['field' => 'lead_score', 'operator' => 'gte', 'value' => '50', 'extra' => 'x']]);

    expect($normalized)->toBe([['field' => 'lead_score', 'operator' => 'gte', 'value' => 50]]);
});

test('lead score conditions match contacts by the highest score of their leads', function () {
    $tenant = segscoreTenant();

    $high = segscoreContact($tenant);
    $low = segscoreContact($tenant);
    $none = segscoreContact($tenant);
    $multi = segscoreContact($tenant);

    segscoreLead($tenant, $high, 80);
    segscoreLead($tenant, $low, 30);
    segscoreLead($tenant, $multi, 20);
    segscoreLead($tenant, $multi, 90);

    expect(segscoreIds($tenant, 'gte', 50))->toEqual([$high->id, $multi->id])
        ->and(segscoreIds($tenant, 'lte', 40))->toEqual([$low->id])
        ->and(segscoreIds($tenant, 'num_equals', 30))->toEqual([$low->id])
        ->and(segscoreIds($tenant, 'is_empty'))->toEqual([$none->id])
        ->and(segscoreIds($tenant, 'is_not_empty'))->toEqual([$high->id, $low->id, $multi->id]);
});

test('soft-deleted leads do not count towards the lead score', function () {
    $tenant = segscoreTenant();

    $contact = segscoreContact($tenant);
    segscoreLead($tenant, $contact, 95, deleted: true);

    expect(segscoreIds($tenant, 'gte', 50))->toEqual([])
        ->and(segscoreIds($tenant, 'is_empty'))->toEqual([$contact->id]);
});

test('lead score segments never cross tenants', function () {
    $a = segscoreTenant();
    $contactA = segscoreContact($a);

    $b = segscoreTenant();
    $contactB = segscoreContact($b);
    segscoreLead($b, $contactB, 99);

    // A forged link: a lead owned by tenant B that points at tenant A's contact must not count.
    segscoreLead($b, $contactA, 99);

    expect(segscoreIds($a, 'gte', 1))->toEqual([])
        ->and(segscoreIds($a, 'is_empty'))->toEqual([$contactA->id])
        ->and(segscoreIds($b, 'gte', 1))->toEqual([$contactB->id]);
});
'@

foreach ($p in $patches) { $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip; Write-Host "$($p.Path): $res" }

$ErrorActionPreference = 'Continue'
Push-Location (Join-Path $repo 'frontend')
$tsc = (& npx.cmd tsc --noEmit 2>&1 | Out-String)
$tscCode = $LASTEXITCODE
Pop-Location

$test = (& php artisan test --no-ansi 2>&1 | Out-String)
$testCode = $LASTEXITCODE
& php artisan queue:restart 2>&1 | Out-Null
$ErrorActionPreference = 'Stop'

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$s) { $report.Add($s); Write-Host $s }
Say "9.7b-2 done. tsc exit code: $tscCode ; php artisan test exit code: $testCode"
if ($tscCode -ne 0) { Say $tsc }
$lines = $test -split "`r?`n"
if ($testCode -ne 0) { Say $test } else { Say (($lines | Select-Object -Last 8) -join "`n") }
Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): SegmentFieldsController.php, segment preview/send code outside SegmentQueryBuilder, any UI that renders a segment condition as text (summary/list), segment.ts, SegmentBuilderPage.tsx.'
