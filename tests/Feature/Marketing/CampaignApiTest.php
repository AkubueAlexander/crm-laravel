<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function mktgUser(Tenant $tenant, string $role = 'admin', array $attributes = []): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(array_merge(['tenant_id' => $tenant->id], $attributes));
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param array<string, mixed> $attributes */
function mktgCampaign(Tenant $tenant, array $attributes = []): Campaign
{
    TenantContext::set($tenant);

    $campaign = new Campaign();
    $campaign->forceFill(array_merge(['tenant_id' => $tenant->id, 'name' => 'Campaign '.Str::random(8)], $attributes))->save();

    return $campaign;
}

function mktgExists(int $id): bool
{
    return DB::table('campaigns')->where('id', $id)->exists();
}

const MKTG_KEYS = [
    'id', 'name', 'subject', 'status', 'editable', 'scheduled_at', 'sent_at', 'recipients_count',
    'sent_count', 'failed_count', 'opened_count', 'clicked_count', 'bounced_count', 'created_at', 'updated_at',
];

it('gives marketing.view to every role and marketing.manage to administrators and managers only', function () {
    foreach (['admin', 'manager', 'sales', 'viewer'] as $role) {
        expect(RoleCatalog::permissionsFor($role))->toContain('marketing.view');
    }

    foreach (['admin', 'manager'] as $role) {
        expect(RoleCatalog::permissionsFor($role))->toContain('marketing.manage');
    }

    foreach (['sales', 'viewer'] as $role) {
        expect(RoleCatalog::permissionsFor($role))->not->toContain('marketing.manage');
    }
});

it('lets a read-only user list and read but never change campaigns', function () {
    $tenant = Tenant::factory()->create();
    $viewer = mktgUser($tenant, 'viewer');
    $campaign = mktgCampaign($tenant, ['name' => 'Keep me']);

    $this->getJson('/api/v1/campaigns')->assertUnauthorized();

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/campaigns')->assertOk();
    $this->getJson("/api/v1/campaigns/{$campaign->id}")->assertOk();
    $this->postJson('/api/v1/campaigns', ['name' => 'New one'])->assertForbidden();
    $this->putJson("/api/v1/campaigns/{$campaign->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->deleteJson("/api/v1/campaigns/{$campaign->id}")->assertForbidden();

    expect(DB::table('campaigns')->count())->toBe(1);
    expect(DB::table('campaigns')->value('name'))->toBe('Keep me');
});

it('lists only the actors tenant with the full resource shape and pagination', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = mktgUser($a);
    mktgCampaign($a, ['name' => 'Alpha']);
    mktgCampaign($a, ['name' => 'Beta']);
    mktgCampaign($b, ['name' => 'B-secret']);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/campaigns')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => MKTG_KEYS],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ])
        ->assertJsonPath('meta.total', 2);

    expect(array_keys($response->json('data.0')))->toEqualCanonicalizing(MKTG_KEYS);
    expect($response->getContent())->not->toContain('B-secret');
    expect($response->json('data.0'))->not->toHaveKey('tenant_id');
    expect($response->json('data.0.status'))->toBe('draft');
    expect($response->json('data.0.editable'))->toBeTrue();
    expect($response->json('data.0.recipients_count'))->toBe(0);
});

it('searches, filters by status, sorts and paginates', function () {
    $tenant = Tenant::factory()->create();
    $admin = mktgUser($tenant);
    mktgCampaign($tenant, ['name' => 'Spring sale', 'subject' => 'Hello there', 'created_at' => now()->subDays(3)]);
    mktgCampaign($tenant, ['name' => 'Autumn news', 'subject' => 'Leaves', 'status' => 'sent', 'created_at' => now()->subDays(2)]);
    mktgCampaign($tenant, ['name' => 'Winter promo', 'subject' => 'Snow', 'created_at' => now()->subDay()]);

    Sanctum::actingAs($admin);

    $names = fn (string $query) => collect($this->getJson("/api/v1/campaigns?{$query}")->assertOk()->json('data'))->pluck('name')->all();

    expect($names(''))->toBe(['Winter promo', 'Autumn news', 'Spring sale']); // newest first by default
    expect($names('q=SPRING'))->toBe(['Spring sale']);
    expect($names('q=leaves'))->toBe(['Autumn news']); // matches the subject too
    expect($names('status=sent'))->toBe(['Autumn news']);
    expect($names('sort=name&direction=asc'))->toBe(['Autumn news', 'Spring sale', 'Winter promo']);
    expect($names('q=%25'))->toBe([]); // a literal percent sign, not a wildcard

    $page2 = $this->getJson('/api/v1/campaigns?per_page=2&page=2&sort=name&direction=asc')->assertOk();
    expect($page2->json('meta.last_page'))->toBe(2);
    expect(collect($page2->json('data'))->pluck('name')->all())->toBe(['Winter promo']);
});

it('rejects a sort column or status outside the whitelist', function () {
    $admin = mktgUser(Tenant::factory()->create());
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/campaigns?sort=tenant_id')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/campaigns?status=exploded')->assertStatus(422)->assertJsonValidationErrors('status');
    $this->getJson('/api/v1/campaigns?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');
});

it('shows one campaign, and never another tenants', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = mktgUser($a);
    $mine = mktgCampaign($a, ['name' => 'Mine']);
    $theirs = mktgCampaign($b, ['name' => 'Theirs']);

    Sanctum::actingAs($admin);

    $this->getJson("/api/v1/campaigns/{$mine->id}")->assertOk()->assertJsonPath('name', 'Mine');
    $this->getJson("/api/v1/campaigns/{$theirs->id}")->assertNotFound();
    $this->getJson('/api/v1/campaigns/999999')->assertNotFound();
});

it('creates a draft whose tenant, status and creator come from the session', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = mktgUser($a);

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/campaigns', [
        'name' => '  Spring Launch ',
        'subject' => 'Hello',
        'tenant_id' => $b->id,
        'status' => 'sent',
        'sent_count' => 99,
        'created_by' => 12345,
    ])->assertCreated()
        ->assertJsonPath('name', 'Spring Launch')
        ->assertJsonPath('subject', 'Hello')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('sent_count', 0);

    expect(array_keys($response->json()))->toEqualCanonicalizing(MKTG_KEYS);

    $row = DB::table('campaigns')->first();
    expect((int) $row->tenant_id)->toBe($a->id);
    expect((int) $row->created_by)->toBe($admin->id);
    expect($row->status)->toBe('draft');
    expect((int) $row->sent_count)->toBe(0);
});

it('validates the name and subject', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(mktgUser($tenant));

    $this->postJson('/api/v1/campaigns', [])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/campaigns', ['name' => str_repeat('x', 161)])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/campaigns', ['name' => 'ok', 'subject' => str_repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors('subject');

    expect(DB::table('campaigns')->count())->toBe(0);
});

it('keeps campaign names unique per tenant, ignoring case, but not across tenants', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = mktgUser($a);
    mktgCampaign($a, ['name' => 'Spring Launch']);
    mktgCampaign($b, ['name' => 'Other Tenant Thing']);

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/campaigns', ['name' => 'spring launch'])->assertStatus(422)->assertJsonValidationErrors('name');
    // The same name in another tenant is not a clash, and does not leak that it exists.
    $this->postJson('/api/v1/campaigns', ['name' => 'Other Tenant Thing'])->assertCreated();

    expect(DB::table('campaigns')->where('tenant_id', $a->id)->count())->toBe(2);
});

it('updates a draft, ignoring its own name in the uniqueness check and any forged fields', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = mktgUser($a);
    $campaign = mktgCampaign($a, ['name' => 'Original', 'subject' => 'Old']);
    mktgCampaign($a, ['name' => 'Taken']);

    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/campaigns/{$campaign->id}", ['name' => 'original', 'subject' => 'New subject', 'tenant_id' => $b->id, 'status' => 'sent'])
        ->assertOk()
        ->assertJsonPath('name', 'original')
        ->assertJsonPath('subject', 'New subject')
        ->assertJsonPath('status', 'draft');

    $this->putJson("/api/v1/campaigns/{$campaign->id}", ['name' => 'TAKEN'])->assertStatus(422)->assertJsonValidationErrors('name');

    $row = DB::table('campaigns')->where('id', $campaign->id)->first();
    expect((int) $row->tenant_id)->toBe($a->id);
    expect($row->status)->toBe('draft');
    expect($row->name)->toBe('original');
});

it('refuses to edit or delete a campaign that is no longer a draft', function () {
    $tenant = Tenant::factory()->create();
    $admin = mktgUser($tenant);
    $sent = mktgCampaign($tenant, ['name' => 'Already out', 'status' => 'sent']);

    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/campaigns/{$sent->id}", ['name' => 'Changed'])->assertStatus(422)->assertJsonValidationErrors('status');
    $this->deleteJson("/api/v1/campaigns/{$sent->id}")->assertStatus(422)->assertJsonValidationErrors('status');

    expect(DB::table('campaigns')->where('id', $sent->id)->value('name'))->toBe('Already out');
    expect(mktgExists($sent->id))->toBeTrue();
    $this->getJson("/api/v1/campaigns/{$sent->id}")->assertOk()->assertJsonPath('editable', false);
});

it('deletes a draft', function () {
    $tenant = Tenant::factory()->create();
    $admin = mktgUser($tenant);
    $campaign = mktgCampaign($tenant);

    Sanctum::actingAs($admin);

    $this->deleteJson("/api/v1/campaigns/{$campaign->id}")->assertNoContent();
    expect(mktgExists($campaign->id))->toBeFalse();
    $this->deleteJson("/api/v1/campaigns/{$campaign->id}")->assertNotFound();
});

it('never updates or deletes another tenants campaign', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = mktgUser($a);
    $theirs = mktgCampaign($b, ['name' => 'Theirs']);

    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/campaigns/{$theirs->id}", ['name' => 'Hijacked'])->assertNotFound();
    $this->deleteJson("/api/v1/campaigns/{$theirs->id}")->assertNotFound();

    expect(DB::table('campaigns')->where('id', $theirs->id)->value('name'))->toBe('Theirs');
});
