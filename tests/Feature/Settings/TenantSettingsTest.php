<?php

use App\Domain\Forecasting\PipelineProbabilities;
use App\Domain\Tenant\TenantContext;
use App\Models\Deal;
use App\Models\PipelineStageSetting;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DevTenantSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function tsetTenant(string $slug): Tenant
{
    return Tenant::create(['name' => $slug, 'slug' => $slug]);
}

function tsetSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

/** @param list<string> $permissions */
function tsetUser(Tenant $tenant, array $permissions = ['settings.manage']): User
{
    tsetSwitch($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
        $user->givePermissionTo($permission);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param array<string, float> $override */
function tsetStages(array $override = []): array
{
    return array_merge(['lead' => 0.1, 'qualified' => 0.3, 'proposal' => 0.5, 'negotiation' => 0.7, 'won' => 1.0], $override);
}

/** @param array<string, mixed> $stages */
function tsetPut(User $user, array $stages)
{
    Sanctum::actingAs($user);

    return test()->putJson('/api/v1/settings/pipeline', ['stages' => $stages]);
}

/** @param array<string, mixed> $data */
function tsetBranding(User $user, array $data)
{
    Sanctum::actingAs($user);

    return test()->post('/api/v1/settings/branding', $data, ['Accept' => 'application/json']);
}

beforeEach(function () {
    Storage::fake('local');
});

it('registers the settings.manage permission for the dev admin', function () {
    expect(DevTenantSeeder::PERMISSIONS)->toContain('settings.manage');
});

it('forbids every settings endpoint without settings.manage', function () {
    $user = tsetUser(tsetTenant('tset-a'), []);
    Sanctum::actingAs($user);

    test()->getJson('/api/v1/settings/pipeline')->assertForbidden();
    tsetPut($user, tsetStages())->assertForbidden();
    tsetBranding($user, ['primary_color' => '#112233'])->assertForbidden();
});

it('returns the five editable stages with defaults and the commit rule', function () {
    $user = tsetUser(tsetTenant('tset-a'));
    Sanctum::actingAs($user);

    $response = test()->getJson('/api/v1/settings/pipeline')
        ->assertOk()
        ->assertJsonStructure([
            'stages' => ['*' => ['stage', 'label', 'probability', 'default_probability', 'is_default', 'commit']],
            'commit_threshold',
        ]);

    expect(collect($response->json('stages'))->pluck('stage')->all())
        ->toBe(['lead', 'qualified', 'proposal', 'negotiation', 'won']);
    expect(collect($response->json('stages'))->every(fn ($s) => $s['is_default'] === true))->toBeTrue();
    expect(collect($response->json('stages'))->pluck('commit')->all())->toBe([false, false, false, true, true]);
    expect($response->json('commit_threshold'))->toBe(0.7);
});

it('stores an override, reflects it, and deletes it again when set back to the default', function () {
    $tenant = tsetTenant('tset-a');
    $user = tsetUser($tenant);

    $response = tsetPut($user, tsetStages(['lead' => 0.2, 'negotiation' => 0.6]))->assertOk();
    expect($response->json('stages.0.probability'))->toBe(0.2);
    expect($response->json('stages.0.is_default'))->toBeFalse();
    expect($response->json('stages.3.commit'))->toBeFalse();

    tsetSwitch($tenant);
    expect(PipelineStageSetting::count())->toBe(2);
    expect(app(PipelineProbabilities::class)->resolve($tenant->id)['lead'])->toBe(0.2);

    tsetPut($user, tsetStages())->assertOk();
    tsetSwitch($tenant);
    expect(PipelineStageSetting::count())->toBe(0);
});

it('validates the stage probabilities', function () {
    $user = tsetUser(tsetTenant('tset-a'));

    tsetPut($user, tsetStages(['lead' => 1.5]))->assertStatus(422)->assertJsonValidationErrors('stages.lead');
    tsetPut($user, tsetStages(['lead' => -0.1]))->assertStatus(422)->assertJsonValidationErrors('stages.lead');
    tsetPut($user, tsetStages(['lead' => 0.555]))->assertStatus(422)->assertJsonValidationErrors('stages.lead');
    tsetPut($user, tsetStages(['lead' => 'abc']))->assertStatus(422)->assertJsonValidationErrors('stages.lead');

    $partial = tsetStages();
    unset($partial['won']);
    tsetPut($user, $partial)->assertStatus(422)->assertJsonValidationErrors('stages.won');
});

it('never lets one tenant change another tenants probabilities', function () {
    $a = tsetTenant('tset-a');
    $b = tsetTenant('tset-b');
    $adminA = tsetUser($a);
    tsetUser($b);

    tsetPut($adminA, tsetStages(['lead' => 0.25]))->assertOk();

    tsetSwitch($b);
    expect(PipelineStageSetting::count())->toBe(0);
    expect(app(PipelineProbabilities::class)->resolve($b->id)['lead'])->toBe(0.1);
});

it('feeds the changed probability into the forecast', function () {
    $tenant = tsetTenant('tset-a');
    $user = tsetUser($tenant, ['settings.manage', 'forecast.view']);

    tsetSwitch($tenant);
    Deal::create(['name' => 'Big deal', 'amount' => 1000]);

    tsetPut($user, tsetStages(['lead' => 0.2]))->assertOk();

    Sanctum::actingAs($user);
    $lead = collect(test()->getJson('/api/v1/forecast')->assertOk()->json('by_stage'))->firstWhere('stage', 'lead');

    expect($lead['weighted_amount'])->toEqualWithDelta(200.0, 0.001);
});

it('stores a re-encoded logo and a lowercase accent color, and returns the tenant shape', function () {
    $tenant = tsetTenant('tset-a');
    $user = tsetUser($tenant);

    $response = tsetBranding($user, [
        'logo' => UploadedFile::fake()->image('logo.png', 600, 400),
        'primary_color' => '#AABBCC',
    ])->assertOk()
        ->assertJsonStructure(['id', 'name', 'slug', 'branding' => ['logo_url', 'primary_color']])
        ->assertJsonPath('branding.primary_color', '#aabbcc');

    expect($response->json('branding.logo_url'))->toStartWith('/api/v1/tenant/logo?v=');

    $stored = Storage::disk('local')->get("tenant-branding/{$tenant->id}/logo.png");
    $info = getimagesizefromstring($stored);

    expect($info[2])->toBe(IMAGETYPE_PNG);
    expect($info[0])->toBe(256);
    expect($info[1])->toBe(171);
});

it('serves the logo only to the tenant that owns it, privately cached', function () {
    $a = tsetTenant('tset-a');
    $b = tsetTenant('tset-b');
    $adminA = tsetUser($a);
    $plainA = tsetUser($a, []);
    $userB = tsetUser($b, []);

    tsetBranding($adminA, ['logo' => UploadedFile::fake()->image('logo.png', 50, 50)])->assertOk();

    // Any signed-in member of the tenant can load it, no settings permission needed.
    Sanctum::actingAs($plainA);
    $response = test()->get('/api/v1/tenant/logo')->assertOk();
    expect($response->headers->get('content-type'))->toContain('image/png');
    expect($response->headers->get('cache-control'))->toContain('private');

    // Another tenant has no logo of its own, and never sees this one.
    Sanctum::actingAs($userB);
    test()->get('/api/v1/tenant/logo')->assertNotFound();
});

it('rejects svg, non-images, oversized files and bad colors', function () {
    $tenant = tsetTenant('tset-a');
    $user = tsetUser($tenant);

    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    tsetBranding($user, ['logo' => $svg])->assertStatus(422)->assertJsonValidationErrors('logo');

    $fake = UploadedFile::fake()->createWithContent('logo.png', 'this is not an image');
    tsetBranding($user, ['logo' => $fake])->assertStatus(422)->assertJsonValidationErrors('logo');

    $big = UploadedFile::fake()->image('big.png', 100, 100)->size(2048);
    tsetBranding($user, ['logo' => $big])->assertStatus(422)->assertJsonValidationErrors('logo');

    tsetBranding($user, ['primary_color' => 'red'])->assertStatus(422)->assertJsonValidationErrors('primary_color');
    tsetBranding($user, ['primary_color' => 'url(javascript:alert(1))'])->assertStatus(422)->assertJsonValidationErrors('primary_color');

    expect(Storage::disk('local')->allFiles('tenant-branding'))->toBeEmpty();
});

it('removes the logo but keeps the accent color, and clears the color when it is blanked', function () {
    $tenant = tsetTenant('tset-a');
    $user = tsetUser($tenant);

    tsetBranding($user, ['logo' => UploadedFile::fake()->image('logo.png', 80, 80), 'primary_color' => '#112233'])->assertOk();
    Storage::disk('local')->assertExists("tenant-branding/{$tenant->id}/logo.png");

    $response = tsetBranding($user, ['remove_logo' => '1'])->assertOk();
    expect($response->json('branding'))->toBe(['primary_color' => '#112233']);
    Storage::disk('local')->assertMissing("tenant-branding/{$tenant->id}/logo.png");

    $response = tsetBranding($user, ['primary_color' => ''])->assertOk();
    expect($response->json('branding'))->toBeNull();
});
