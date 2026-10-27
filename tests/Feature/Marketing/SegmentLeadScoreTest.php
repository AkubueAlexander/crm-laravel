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