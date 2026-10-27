<?php

use App\Domain\Leads\Forms\CaptchaVerifier;
use App\Domain\Tenant\TenantContext;
use App\Jobs\EnrollForTriggerJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function lformpubTenant(): Tenant
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);

    return $tenant;
}

/** Inserts a form and returns its public uuid. */
function lformpubForm(Tenant $tenant, array $overrides = []): string
{
    $uuid = (string) Str::uuid();

    if (isset($overrides['fields']) && is_array($overrides['fields'])) {
        $overrides['fields'] = json_encode($overrides['fields']);
    }

    DB::table('lead_forms')->insert(array_merge([
        'tenant_id' => $tenant->id,
        'uuid' => $uuid,
        'name' => 'Form '.$uuid,
        'is_active' => true,
        'source' => 'website',
        'success_message' => 'Thanks, talk soon!',
        'fields' => json_encode([
            ['key' => 'first_name', 'label' => 'First name', 'required' => true],
            ['key' => 'last_name', 'label' => 'Last name', 'required' => false],
            ['key' => 'email', 'label' => 'Email', 'required' => true],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $uuid;
}

function lformpubLeads(Tenant $tenant): \Illuminate\Support\Collection
{
    return DB::table('leads')->where('tenant_id', $tenant->id)->get();
}

test('the public definition exposes only the form name, fields and captcha settings', function () {
    $tenant = lformpubTenant();
    $uuid = lformpubForm($tenant, ['name' => 'Contact us']);

    $response = $this->getJson("/api/public/forms/{$uuid}")->assertOk();

    expect(array_keys($response->json()))->toEqual(['name', 'fields', 'captcha'])
        ->and($response->json('name'))->toBe('Contact us')
        ->and(collect($response->json('fields'))->pluck('key')->all())->toBe(['first_name', 'last_name', 'email'])
        ->and($response->json('captcha.enabled'))->toBeFalse();
});

test('unknown, inactive and malformed form ids are all a plain 404', function () {
    $tenant = lformpubTenant();
    $inactive = lformpubForm($tenant, ['is_active' => false]);

    $this->getJson('/api/public/forms/'.Str::uuid())->assertNotFound();
    $this->getJson("/api/public/forms/{$inactive}")->assertNotFound();
    $this->getJson('/api/public/forms/not-a-uuid')->assertNotFound();
    $this->postJson("/api/public/forms/{$inactive}", ['first_name' => 'Ada', 'email' => 'ada@example.test'])->assertNotFound();

    expect(lformpubLeads($tenant))->toHaveCount(0);
});

test('a submission creates a new, unowned, scored lead in the forms own tenant', function () {
    $tenant = lformpubTenant();
    $uuid = lformpubForm($tenant);

    $other = lformpubTenant();
    TenantContext::set($tenant);

    $this->postJson("/api/public/forms/{$uuid}", [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.test',
        // Forged values the visitor must not be able to set:
        'status' => 'converted',
        'owner_id' => 1,
        'tenant_id' => $other->id,
        'lead_score' => 100,
        'source' => 'evil',
        'company' => 'Not on this form',
    ])->assertCreated()->assertJsonPath('message', 'Thanks, talk soon!');

    $leads = lformpubLeads($tenant);

    expect($leads)->toHaveCount(1)
        ->and($leads[0]->first_name)->toBe('Ada')
        ->and($leads[0]->email)->toBe('ada@example.test')
        ->and($leads[0]->status)->toBe('new')
        ->and($leads[0]->owner_id)->toBeNull()
        ->and($leads[0]->source)->toBe('website')
        ->and($leads[0]->company)->toBeNull()
        ->and((int) $leads[0]->lead_score)->toBeGreaterThan(0)->toBeLessThan(100)
        ->and(lformpubLeads($other))->toHaveCount(0);
});

test('it validates against the forms own field list', function () {
    $tenant = lformpubTenant();
    $uuid = lformpubForm($tenant);

    $this->postJson("/api/public/forms/{$uuid}", ['email' => 'ada@example.test'])
        ->assertStatus(422)->assertJsonValidationErrors(['first_name']);

    $this->postJson("/api/public/forms/{$uuid}", ['first_name' => 'Ada', 'email' => 'not-an-email'])
        ->assertStatus(422)->assertJsonValidationErrors(['email']);

    $this->postJson("/api/public/forms/{$uuid}", ['first_name' => ['x'], 'email' => 'ada@example.test'])
        ->assertStatus(422)->assertJsonValidationErrors(['first_name']);

    expect(lformpubLeads($tenant))->toHaveCount(0);
});

test('a filled honeypot gets the same success and stores nothing', function () {
    $tenant = lformpubTenant();
    $uuid = lformpubForm($tenant);

    $this->postJson("/api/public/forms/{$uuid}", ['first_name' => 'Bot', 'email' => 'bot@example.test', 'website' => 'http://spam.test'])
        ->assertCreated()->assertJsonPath('message', 'Thanks, talk soon!');

    expect(lformpubLeads($tenant))->toHaveCount(0);
});

test('a repeated email creates one lead per tenant but still answers success', function () {
    $tenant = lformpubTenant();
    $uuid = lformpubForm($tenant);

    $payload = ['first_name' => 'Ada', 'email' => 'Ada@Example.test'];

    $this->postJson("/api/public/forms/{$uuid}", $payload)->assertCreated();
    $this->postJson("/api/public/forms/{$uuid}", ['first_name' => 'Ada Again', 'email' => 'ada@example.test'])->assertCreated();

    expect(lformpubLeads($tenant))->toHaveCount(1);

    $other = lformpubTenant();
    $otherUuid = lformpubForm($other);

    $this->postJson("/api/public/forms/{$otherUuid}", $payload)->assertCreated();

    expect(lformpubLeads($other))->toHaveCount(1);
});

test('a submission fires active lead_created automations like any other new lead', function () {
    Queue::fake();

    $tenant = lformpubTenant();
    $uuid = lformpubForm($tenant);

    DB::table('automations')->insert([
        'tenant_id' => $tenant->id,
        'name' => 'On new lead',
        'status' => 'active',
        'trigger_type' => 'lead_created',
        'trigger_config' => '{}',
        'lock_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson("/api/public/forms/{$uuid}", ['first_name' => 'Ada', 'email' => 'ada@example.test'])->assertCreated();

    Queue::assertPushed(EnrollForTriggerJob::class);
});

test('turnstile is verified when configured and refuses a failed or missing token', function () {
    config(['services.turnstile.secret' => 'sek', 'services.turnstile.site_key' => 'site']);

    $tenant = lformpubTenant();
    $uuid = lformpubForm($tenant);
    $payload = ['first_name' => 'Ada', 'email' => 'ada@example.test'];

    expect($this->getJson("/api/public/forms/{$uuid}")->json('captcha'))->toEqual(['enabled' => true, 'site_key' => 'site']);

    // One stub with a sequence: a later Http::fake() does not override an earlier one. Only calls that carry a token
    // reach Turnstile: the 'bad' token gets the first answer (failure), 'tok' the second (success).
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

    $this->postJson("/api/public/forms/{$uuid}", $payload)->assertStatus(422)->assertJsonValidationErrors(['captcha']);
    $this->postJson("/api/public/forms/{$uuid}", $payload + ['captcha_token' => 'bad'])->assertStatus(422)->assertJsonValidationErrors(['captcha']);

    expect(lformpubLeads($tenant))->toHaveCount(0);

    // (the sequence above answers the next call with success)

    $this->postJson("/api/public/forms/{$uuid}", $payload + ['captcha_token' => 'tok'])->assertCreated();

    Http::assertSent(fn ($request) => $request['secret'] === 'sek' && $request['response'] === 'tok');

    expect(lformpubLeads($tenant))->toHaveCount(1);
});

test('with no secret the captcha check only passes in local and testing', function () {
    config(['services.turnstile.secret' => '']);

    try {
        app()->detectEnvironment(fn () => 'production');
        expect(CaptchaVerifier::passes(null, null))->toBeFalse()
            ->and(CaptchaVerifier::passes('anything', null))->toBeFalse();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    expect(CaptchaVerifier::passes(null, null))->toBeTrue();
});

test('submissions are throttled per client', function () {
    $tenant = lformpubTenant();
    $uuid = lformpubForm($tenant);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson("/api/public/forms/{$uuid}", ['website' => 'x'])->assertCreated();
    }

    $this->postJson("/api/public/forms/{$uuid}", ['website' => 'x'])->assertStatus(429);
});