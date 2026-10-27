<?php

use App\Domain\Marketing\Segments\SegmentDsl;
use App\Domain\Marketing\Segments\SegmentQueryBuilder;
use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** @param array<string, mixed> $attributes */
function segmContact(Tenant $tenant, string $lastName, array $attributes = []): int
{
    return DB::table('contacts')->insertGetId(array_merge([
        'tenant_id' => $tenant->id,
        'first_name' => null,
        'last_name' => $lastName,
        'email' => null,
        'phone' => null,
        'job_title' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

function segmC(string $field, string $operator, mixed $value = null): array
{
    return ['field' => $field, 'operator' => $operator, 'value' => $value];
}

/**
 * Last names of the contacts a definition matches, in insertion order.
 *
 * @param list<array<string, mixed>> $conditions
 * @return list<string>
 */
function segmNames(Tenant $tenant, array $conditions, string $mode = 'all'): array
{
    TenantContext::set($tenant); // the tenant a request or job runs as

    expect(SegmentDsl::errors($conditions))->toBe([]);

    return SegmentQueryBuilder::contactsFor($tenant->id, $mode, SegmentDsl::normalize($conditions))
        ->orderBy('contacts.id')
        ->pluck('contacts.last_name')
        ->all();
}

/** Tenant A: Alice, Bob, Carol, Dave (+ a soft-deleted Eve). Tenant B: another Alice and Zed. */
function segmSeed(): array
{
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    segmContact($a, 'Alice', ['email' => 'alice@acme.test', 'job_title' => 'CEO', 'phone' => '12', 'created_at' => now()->subDays(40)]);
    segmContact($a, 'Bob', ['email' => 'bob@gmail.com', 'job_title' => 'Sales Manager', 'phone' => '+234 801 234 5678', 'created_at' => now()->subDays(10)]);
    segmContact($a, 'Carol', ['created_at' => now()->subDays(2)]);
    segmContact($a, 'Dave', ['email' => '   ', 'job_title' => '', 'created_at' => now()->subDays(100)]);
    segmContact($a, 'Eve', ['email' => 'eve@acme.test', 'deleted_at' => now()]);
    segmContact($b, 'Alice', ['email' => 'alice@other.test']);
    segmContact($b, 'Zed', ['email' => 'zed@acme.test']);

    return [$a, $b];
}

it('exposes a field catalog whose every operator is known and evaluable', function () {
    $tenant = Tenant::factory()->create();
    segmContact($tenant, 'Solo');
    TenantContext::set($tenant); // ambient tenant for the generated SQL

    $fields = SegmentDsl::fields();

    expect(collect($fields)->pluck('field')->all())->toBe(['name', 'email', 'job_title', 'phone', 'account', 'created_at', 'lead_score']);

    foreach ($fields as $field) {
        expect($field)->toHaveKeys(['field', 'label', 'type', 'operators']);
        expect($field['operators'])->not->toBeEmpty();

        foreach ($field['operators'] as $operator) {
            expect(array_keys(SegmentDsl::OPERATORS))->toContain($operator['operator']);

            $value = match ($operator['value_kind']) {
                'string' => 'x',
                'date' => '2026-01-01',
                'days' => 7,
                'number' => 50,
                default => null,
            };
            $conditions = [segmC($field['field'], $operator['operator'], $value)];

            expect(SegmentDsl::errors($conditions))->toBe([]);
            // Runs the generated SQL against Postgres: a typo in any expression fails here.
            expect(SegmentQueryBuilder::count($tenant->id, 'all', SegmentDsl::normalize($conditions)))->toBeInt();
        }
    }
});

it('reports validation errors keyed like Laravel errors, and never throws on garbage', function () {
    expect(SegmentDsl::errors([]))->toHaveKey('conditions');
    expect(SegmentDsl::errors('nope'))->toHaveKey('conditions');
    expect(SegmentDsl::errors(null))->toHaveKey('conditions');
    expect(SegmentDsl::errors(['a' => segmC('name', 'contains', 'x')]))->toHaveKey('conditions'); // not a list
    expect(SegmentDsl::errors(array_fill(0, 21, segmC('name', 'contains', 'x'))))->toHaveKey('conditions');
    expect(SegmentDsl::errors(array_fill(0, 20, segmC('name', 'contains', 'x'))))->toBe([]);

    expect(SegmentDsl::errors(['x']))->toHaveKey('conditions.0');
    expect(SegmentDsl::errors([segmC('nope', 'contains', 'x')]))->toHaveKey('conditions.0.field');
    expect(SegmentDsl::errors([['operator' => 'contains', 'value' => 'x']]))->toHaveKey('conditions.0.field');
    expect(SegmentDsl::errors([segmC('name', 'is_before', 'x')]))->toHaveKey('conditions.0.operator');
    expect(SegmentDsl::errors([segmC('phone', 'contains', 'x')]))->toHaveKey('conditions.0.operator'); // wrong operator for the type
    expect(SegmentDsl::errors([['field' => 'name', 'operator' => ['contains'], 'value' => 'x']]))->toHaveKey('conditions.0.operator');

    // Errors point at the failing row, not the whole list.
    $errors = SegmentDsl::errors([segmC('name', 'contains', 'ok'), segmC('email', 'equals', '  ')]);
    expect(array_keys($errors))->toBe(['conditions.1.value']);

    foreach ([null, '', '   ', 12, ['x'], str_repeat('x', 101)] as $bad) {
        expect(SegmentDsl::errors([segmC('name', 'contains', $bad)]))->toHaveKey('conditions.0.value');
    }

    foreach (['abc', '2026-02-30', '2026-1-5', '', null, 20260101, '2026-01-01 10:00'] as $bad) {
        expect(SegmentDsl::errors([segmC('created_at', 'before', $bad)]))->toHaveKey('conditions.0.value');
    }
    expect(SegmentDsl::errors([segmC('created_at', 'before', '2026-02-28')]))->toBe([]);

    foreach ([0, -1, 3651, 1.5, 'x', '', null, '1e3', '-5'] as $bad) {
        expect(SegmentDsl::errors([segmC('created_at', 'in_last_days', $bad)]))->toHaveKey('conditions.0.value');
    }
    foreach ([1, 7, 3650, '7'] as $ok) {
        expect(SegmentDsl::errors([segmC('created_at', 'in_last_days', $ok)]))->toBe([]);
    }

    // No-value operators ignore whatever value rides along.
    expect(SegmentDsl::errors([segmC('email', 'is_empty', ['junk'])]))->toBe([]);
});

it('asserts validity with a ValidationException covering match_mode and conditions', function () {
    expect(fn () => SegmentDsl::assertValid('all', [segmC('name', 'contains', 'x')]))->not->toThrow(ValidationException::class);

    try {
        SegmentDsl::assertValid('sometimes', []);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['match_mode', 'conditions']);
    }

    expect(fn () => SegmentDsl::assertValid(['all'], [segmC('name', 'contains', 'x')]))->toThrow(ValidationException::class);
});

it('normalizes to field, operator and value only, with the value coerced to its kind', function () {
    $normalized = SegmentDsl::normalize([
        ['field' => 'name', 'operator' => 'contains', 'value' => '  Ann  ', 'extra' => 'x', 'tenant_id' => 1],
        ['field' => 'email', 'operator' => 'is_empty', 'value' => 'ignored'],
        ['field' => 'created_at', 'operator' => 'in_last_days', 'value' => '7'],
        ['field' => 'created_at', 'operator' => 'before', 'value' => '2026-03-01'],
    ]);

    expect($normalized)->toBe([
        ['field' => 'name', 'operator' => 'contains', 'value' => 'Ann'],
        ['field' => 'email', 'operator' => 'is_empty', 'value' => null],
        ['field' => 'created_at', 'operator' => 'in_last_days', 'value' => 7],
        ['field' => 'created_at', 'operator' => 'before', 'value' => '2026-03-01'],
    ]);
});

it('matches text operators case-insensitively and treats a missing value as empty', function () {
    [$a] = segmSeed();

    expect(segmNames($a, [segmC('name', 'contains', 'ALI')]))->toBe(['Alice']);
    expect(segmNames($a, [segmC('name', 'equals', 'bob')]))->toBe(['Bob']);
    expect(segmNames($a, [segmC('email', 'contains', 'acme')]))->toBe(['Alice']); // not the deleted Eve, not tenant B's Zed
    expect(segmNames($a, [segmC('email', 'starts_with', 'BOB@')]))->toBe(['Bob']);
    expect(segmNames($a, [segmC('email', 'not_contains', 'gmail')]))->toBe(['Alice', 'Carol', 'Dave']); // no email counts as not containing
    expect(segmNames($a, [segmC('email', 'is_empty')]))->toBe(['Carol', 'Dave']); // whitespace-only is empty
    expect(segmNames($a, [segmC('email', 'is_not_empty')]))->toBe(['Alice', 'Bob']);
    expect(segmNames($a, [segmC('job_title', 'equals', 'ceo')]))->toBe(['Alice']);
    expect(segmNames($a, [segmC('job_title', 'not_equals', 'ceo')]))->toBe(['Bob', 'Carol', 'Dave']);
    expect(segmNames($a, [segmC('job_title', 'starts_with', 'sales')]))->toBe(['Bob']);
    expect(segmNames($a, [segmC('job_title', 'is_empty')]))->toBe(['Carol', 'Dave']); // '' and null
});

it('treats percent, underscore and backslash in a value literally', function () {
    [$a] = segmSeed();

    expect(segmNames($a, [segmC('name', 'contains', '%')]))->toBe([]);
    expect(segmNames($a, [segmC('name', 'contains', '_')]))->toBe([]);
    expect(segmNames($a, [segmC('name', 'contains', '\\')]))->toBe([]);
    expect(segmNames($a, [segmC('email', 'starts_with', '%')]))->toBe([]);
    expect(segmNames($a, [segmC('name', 'contains', "x' or 1=1 --")]))->toBe([]);
});

it('combines conditions with all (AND) and any (OR) without escaping the tenant', function () {
    [$a, $b] = segmSeed();

    expect(segmNames($a, [segmC('job_title', 'contains', 'manager'), segmC('email', 'contains', 'gmail')], 'all'))->toBe(['Bob']);
    expect(segmNames($a, [segmC('job_title', 'contains', 'manager'), segmC('email', 'contains', 'acme')], 'all'))->toBe([]);
    expect(segmNames($a, [segmC('name', 'contains', 'alice'), segmC('name', 'contains', 'carol')], 'any'))->toBe(['Alice', 'Carol']);

    // "any" with a condition that also matches other tenants' rows stays inside the tenant.
    expect(segmNames($a, [segmC('email', 'contains', 'acme'), segmC('name', 'equals', 'zed')], 'any'))->toBe(['Alice']);
    expect(segmNames($b, [segmC('email', 'contains', 'acme'), segmC('name', 'equals', 'alice')], 'any'))->toBe(['Alice', 'Zed']);
});

it('matches phone and account presence', function () {
    [$a] = segmSeed();

    expect(segmNames($a, [segmC('phone', 'is_not_empty')]))->toBe(['Bob']); // Alice's "12" is not a usable number
    expect(segmNames($a, [segmC('phone', 'is_empty')]))->toBe(['Alice', 'Carol', 'Dave']);
    expect(segmNames($a, [segmC('account', 'is_empty')]))->toBe(['Alice', 'Bob', 'Carol', 'Dave']);
    expect(segmNames($a, [segmC('account', 'is_not_empty')]))->toBe([]);
});

it('matches created_at with before, after and in_last_days', function () {
    [$a] = segmSeed();

    $thirtyDaysAgo = now()->subDays(30)->format('Y-m-d');

    expect(segmNames($a, [segmC('created_at', 'before', $thirtyDaysAgo)]))->toBe(['Alice', 'Dave']);
    expect(segmNames($a, [segmC('created_at', 'after', $thirtyDaysAgo)]))->toBe(['Bob', 'Carol']);
    expect(segmNames($a, [segmC('created_at', 'in_last_days', 5)]))->toBe(['Carol']);
    expect(segmNames($a, [segmC('created_at', 'in_last_days', 50)]))->toBe(['Alice', 'Bob', 'Carol']);
});

it('counts per tenant and never reads another tenants contacts', function () {
    [$a, $b] = segmSeed();
    TenantContext::set($a); // ambient tenant for this count

    $everyone = SegmentDsl::normalize([segmC('name', 'is_not_empty')]);
    expect(SegmentQueryBuilder::count($a->id, 'all', $everyone))->toBe(4); // Eve is soft-deleted
    // Even if the ambient tenant were wrong, the explicit predicate keeps tenant B's contacts out of A's count.
    expect(SegmentQueryBuilder::count($b->id, 'all', $everyone))->toBe(0);
    TenantContext::set($b);
    expect(SegmentQueryBuilder::count($b->id, 'all', $everyone))->toBe(2);
    expect(SegmentQueryBuilder::count($a->id, 'all', $everyone))->toBe(0);
});
