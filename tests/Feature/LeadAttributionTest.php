<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

function attrSetup(): array
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$tenant, $user];
}

function attrForm(Tenant $tenant, User $user): array
{
    $uuid = (string) Str::uuid();
    $id = DB::table('lead_forms')->insertGetId([
        'tenant_id' => $tenant->id,
        'uuid' => $uuid,
        'name' => 'Landing',
        'is_active' => true,
        'source' => 'website',
        'success_message' => null,
        'fields' => json_encode([
            ['key' => 'first_name', 'label' => 'First name', 'required' => true],
            ['key' => 'email', 'label' => 'Email', 'required' => true],
        ]),
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [(int) $id, $uuid];
}

it('attr stores the form and sanitized utm values on a public submission', function () {
    Queue::fake();
    [$tenant, $user] = attrSetup();
    [$formId, $uuid] = attrForm($tenant, $user);

    $this->postJson("/api/public/forms/{$uuid}", [
        'first_name' => 'Ada',
        'email' => 'ada@example.test',
        'utm_source' => 'newsletter',
        'utm_medium' => "em\x00ail",
        'utm_campaign' => str_repeat('x', 150),
        'lead_form_id' => 999999, // a visitor cannot choose this
    ])->assertCreated();

    $lead = DB::table('leads')->where('tenant_id', $tenant->id)->where('email', 'ada@example.test')->first();

    expect($lead)->not->toBeNull();
    expect((int) $lead->lead_form_id)->toBe($formId);
    expect($lead->utm_source)->toBe('newsletter');
    expect($lead->utm_medium)->toBe('email');
    expect(strlen((string) $lead->utm_campaign))->toBe(100);
});

it('attr leaves utm empty when the visitor sends none', function () {
    Queue::fake();
    [$tenant, $user] = attrSetup();
    [$formId, $uuid] = attrForm($tenant, $user);

    $this->postJson("/api/public/forms/{$uuid}", ['first_name' => 'Bo', 'email' => 'bo@example.test'])->assertCreated();

    $lead = DB::table('leads')->where('tenant_id', $tenant->id)->where('email', 'bo@example.test')->first();

    expect((int) $lead->lead_form_id)->toBe($formId);
    expect($lead->utm_source)->toBeNull();
    expect($lead->utm_campaign)->toBeNull();
});

it('attr carries source and attribution from the lead onto the deal', function () {
    Queue::fake();
    [$tenant, $user] = attrSetup();
    [$formId] = attrForm($tenant, $user);

    $lead = Lead::factory()->create([
        'first_name' => 'Ada',
        'last_name' => 'Obi',
        'company' => null,
        'status' => 'new',
        'source' => 'website',
    ]);
    DB::table('leads')->where('id', $lead->id)->update([
        'lead_form_id' => $formId,
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'spring',
    ]);

    $this->actingAs($user)->getJson("/api/v1/leads/{$lead->id}")
        ->assertOk()
        ->assertJsonPath('utm_campaign', 'spring')
        ->assertJsonPath('lead_form_id', $formId);

    $this->actingAs($user)->postJson("/api/v1/leads/{$lead->id}/convert")->assertOk();

    $dealId = DB::table('leads')->where('id', $lead->id)->value('converted_deal_id');
    $deal = DB::table('deals')->where('id', $dealId)->first();

    expect($deal->source)->toBe('website');
    expect((int) $deal->lead_form_id)->toBe($formId);
    expect($deal->utm_source)->toBe('newsletter');
    expect($deal->utm_medium)->toBe('email');
    expect($deal->utm_campaign)->toBe('spring');
});

it('attr converts a lead without attribution and leaves the deal columns empty', function () {
    Queue::fake();
    [, $user] = attrSetup();

    $lead = Lead::factory()->create([
        'first_name' => 'Cy',
        'last_name' => 'Eze',
        'company' => null,
        'status' => 'new',
        'source' => null,
    ]);

    $this->actingAs($user)->postJson("/api/v1/leads/{$lead->id}/convert")->assertOk();

    $dealId = DB::table('leads')->where('id', $lead->id)->value('converted_deal_id');
    $deal = DB::table('deals')->where('id', $dealId)->first();

    expect($deal->source)->toBeNull();
    expect($deal->lead_form_id)->toBeNull();
    expect($deal->utm_campaign)->toBeNull();
});
