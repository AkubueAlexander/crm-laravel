# crm-9-3b-tests.ps1 - API tests for email templates + a small actor-resolution patch. Run from the repo root.
$ErrorActionPreference = 'Stop'
$root = (Get-Location).Path
if (-not (Test-Path (Join-Path $root 'routes\email_templates.php'))) {
    throw "9.3b is not applied (routes\email_templates.php not found). Run crm-9-3b.ps1 first."
}

function Write-NoBomAbs {
    param([string]$FullPath, [string]$Content)
    $dir = Split-Path $FullPath -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [System.IO.File]::WriteAllText($FullPath, $Content.Replace("`r`n", "`n"), (New-Object System.Text.UTF8Encoding($false)))
    Write-Host ("wrote " + $FullPath.Substring($root.Length + 1))
}

# ---- 1. Patch: resolve the actor the way the test helpers authenticate (Sanctum::actingAs sets the
# sanctum guard, not 'web'). Auth::user() follows the default guard, which is the session user in
# production and the acting-as user in tests. Files are the ones generated in 9.3b; each must contain the call.
$needle = "Auth::guard('web')->user()"
foreach ($name in @('EmailTemplateController.php', 'ListEmailTemplatesRequest.php', 'SaveEmailTemplateRequest.php', 'PreviewEmailTemplateRequest.php')) {
    $f = Get-ChildItem (Join-Path $root 'app') -Recurse -Filter $name | Select-Object -First 1
    if (-not $f) { throw "Could not find $name under app\." }
    $raw = [System.IO.File]::ReadAllText($f.FullName)
    if (-not $raw.Contains($needle)) {
        if ($raw.Contains('Auth::user()')) { Write-Host "$name already patched (skipped)"; continue }
        throw "$name does not contain $needle"
    }
    $crlf = $raw.Contains("`r`n")
    $out = $raw.Replace($needle, 'Auth::user()')
    [System.IO.File]::WriteAllText($f.FullName, $out, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "patched $name"
}

# ---- 2. Tests
Write-NoBomAbs (Join-Path $root 'tests\Feature\Marketing\EmailTemplateApiTest.php') @'
<?php

declare(strict_types=1);

use App\Domain\Marketing\Actions\CreateEmailTemplateAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\EmailTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function tmplapiUser(Tenant $tenant, string $role = 'admin'): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param array<string, mixed> $attributes */
function tmplapiTemplate(Tenant $tenant, array $attributes = []): EmailTemplate
{
    TenantContext::set($tenant);

    $template = new EmailTemplate();
    $template->forceFill(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Template '.Str::random(8),
        'subject' => 'Hello {{ first_name }}',
        'body' => "# Hi {{ name }}\n\nBody text.",
    ], $attributes))->save();

    return $template;
}

/** @param array<string, mixed> $overrides */
function tmplapiPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Welcome',
        'subject' => 'Welcome, {{ first_name }}',
        'body' => "# Hello {{ name }}\n\nThanks for joining.",
    ], $overrides);
}

const TMPLAPI_KEYS = ['id', 'name', 'subject', 'body', 'created_at', 'updated_at'];

it('gives every role read access and only administrators and managers write access to templates', function () {
    $tenant = Tenant::factory()->create();
    $template = tmplapiTemplate($tenant, ['name' => 'Keep me']);

    $this->getJson('/api/v1/email-templates')->assertUnauthorized();
    $this->getJson('/api/v1/email-templates/variables')->assertUnauthorized();
    $this->postJson('/api/v1/email-templates/preview', tmplapiPayload())->assertUnauthorized();

    foreach (['sales', 'viewer'] as $role) {
        Sanctum::actingAs(tmplapiUser($tenant, $role));

        $this->getJson('/api/v1/email-templates')->assertOk();
        $this->getJson("/api/v1/email-templates/{$template->id}")->assertOk();
        $this->getJson('/api/v1/email-templates/variables')->assertOk();
        $this->postJson('/api/v1/email-templates/preview', tmplapiPayload())->assertOk();
        $this->postJson('/api/v1/email-templates', tmplapiPayload(['name' => "New {$role}"]))->assertForbidden();
        $this->putJson("/api/v1/email-templates/{$template->id}", tmplapiPayload(['name' => 'Renamed']))->assertForbidden();
        $this->deleteJson("/api/v1/email-templates/{$template->id}")->assertForbidden();
    }

    expect(DB::table('email_templates')->count())->toBe(1);

    foreach (['admin', 'manager'] as $role) {
        Sanctum::actingAs(tmplapiUser($tenant, $role));

        $this->postJson('/api/v1/email-templates', tmplapiPayload(['name' => "Created by {$role}"]))->assertCreated();
    }

    expect(DB::table('email_templates')->count())->toBe(3);
});

it('creates a template for the actor tenant and ignores a client supplied tenant', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $admin = tmplapiUser($tenant);
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/email-templates', tmplapiPayload([
        'tenant_id' => $other->id,
        'created_by' => 999999,
    ]))->assertCreated();

    expect(array_keys($response->json()))->toEqualCanonicalizing(TMPLAPI_KEYS)
        ->and($response->json('name'))->toBe('Welcome');

    $row = DB::table('email_templates')->where('id', $response->json('id'))->first();
    expect($row->tenant_id)->toBe($tenant->id)
        ->and($row->created_by)->toBe($admin->id);
});

it('lists only the tenant templates without the body, with search, sort and escaped wildcards', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    tmplapiTemplate($tenant, ['name' => 'Beta', 'subject' => 'Second']);
    tmplapiTemplate($tenant, ['name' => 'Alpha', 'subject' => 'First']);
    tmplapiTemplate($other, ['name' => 'Foreign']);

    Sanctum::actingAs(tmplapiUser($tenant, 'viewer'));

    $list = $this->getJson('/api/v1/email-templates?sort=name&direction=asc')->assertOk();
    expect(array_column($list->json('data'), 'name'))->toBe(['Alpha', 'Beta'])
        ->and($list->json('data.0'))->not->toHaveKey('body')
        ->and($list->json('meta.total'))->toBe(2);

    $search = $this->getJson('/api/v1/email-templates?q=alp')->assertOk();
    expect(array_column($search->json('data'), 'name'))->toBe(['Alpha']);

    $bySubject = $this->getJson('/api/v1/email-templates?q=second')->assertOk();
    expect(array_column($bySubject->json('data'), 'name'))->toBe(['Beta']);

    // A literal % must not act as a LIKE wildcard.
    $wildcard = $this->getJson('/api/v1/email-templates?q=%25')->assertOk();
    expect($wildcard->json('data'))->toBe([]);

    $this->getJson('/api/v1/email-templates?sort=body')->assertUnprocessable();
    $this->getJson('/api/v1/email-templates?direction=sideways')->assertUnprocessable();
});

it('shows one template with its body', function () {
    $tenant = Tenant::factory()->create();
    $template = tmplapiTemplate($tenant, ['name' => 'Full', 'body' => 'Line one']);
    Sanctum::actingAs(tmplapiUser($tenant, 'sales'));

    $response = $this->getJson("/api/v1/email-templates/{$template->id}")->assertOk();

    expect(array_keys($response->json()))->toEqualCanonicalizing(TMPLAPI_KEYS)
        ->and($response->json('body'))->toBe('Line one');
});

it('never reads, changes or deletes another tenant template', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $foreign = tmplapiTemplate($other, ['name' => 'Foreign', 'subject' => 'Untouched']);
    Sanctum::actingAs(tmplapiUser($tenant));

    $this->getJson("/api/v1/email-templates/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/v1/email-templates/{$foreign->id}", tmplapiPayload(['name' => 'Hijacked']))->assertNotFound();
    $this->deleteJson("/api/v1/email-templates/{$foreign->id}")->assertNotFound();

    $row = DB::table('email_templates')->where('id', $foreign->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Foreign')
        ->and($row->subject)->toBe('Untouched');
});

it('updates a template and deletes it', function () {
    $tenant = Tenant::factory()->create();
    $template = tmplapiTemplate($tenant, ['name' => 'Old name']);
    Sanctum::actingAs(tmplapiUser($tenant, 'manager'));

    $response = $this->putJson("/api/v1/email-templates/{$template->id}", tmplapiPayload([
        'name' => 'New name',
        'subject' => 'New subject',
        'body' => 'New body',
    ]))->assertOk();

    expect($response->json('name'))->toBe('New name')
        ->and($response->json('body'))->toBe('New body');

    $row = DB::table('email_templates')->where('id', $template->id)->first();
    expect($row->subject)->toBe('New subject');

    $this->deleteJson("/api/v1/email-templates/{$template->id}")->assertNoContent();
    expect(DB::table('email_templates')->where('id', $template->id)->exists())->toBeFalse();
});

it('rejects duplicate names ignoring case within a tenant but allows them across tenants', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $first = tmplapiTemplate($tenant, ['name' => 'Welcome']);
    $second = tmplapiTemplate($tenant, ['name' => 'Second']);
    tmplapiTemplate($other, ['name' => 'Shared name']);

    Sanctum::actingAs(tmplapiUser($tenant));

    $this->postJson('/api/v1/email-templates', tmplapiPayload(['name' => 'welcome']))
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->putJson("/api/v1/email-templates/{$second->id}", tmplapiPayload(['name' => 'WELCOME']))
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);

    // Saving a template under its own name is fine.
    $this->putJson("/api/v1/email-templates/{$first->id}", tmplapiPayload(['name' => 'Welcome', 'body' => 'Changed']))
        ->assertOk();

    // Another tenant already uses this name.
    $this->postJson('/api/v1/email-templates', tmplapiPayload(['name' => 'Shared name']))->assertCreated();
});

it('validates the template and reports unknown variables on the right field', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(tmplapiUser($tenant));

    $this->postJson('/api/v1/email-templates', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'subject', 'body']);

    $this->postJson('/api/v1/email-templates', tmplapiPayload(['body' => 'Hi {{ ghost }}']))
        ->assertUnprocessable()->assertJsonValidationErrors(['body']);

    $this->postJson('/api/v1/email-templates', tmplapiPayload(['subject' => 'Hi {{ Name }}']))
        ->assertUnprocessable()->assertJsonValidationErrors(['subject']);

    $this->postJson('/api/v1/email-templates', tmplapiPayload(['subject' => "Two\nlines"]))
        ->assertUnprocessable()->assertJsonValidationErrors(['subject']);

    $this->postJson('/api/v1/email-templates', tmplapiPayload(['subject' => str_repeat('a', 201)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['subject']);

    expect(DB::table('email_templates')->count())->toBe(0);
});

it('previews with sample data through the shared renderer without saving anything', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(tmplapiUser($tenant, 'viewer'));

    $response = $this->postJson('/api/v1/email-templates/preview', [
        'subject' => 'Hi {{ first_name }} {{ ghost }}',
        'body' => "# Hi {{ name }}\n\n<script>alert(1)</script>",
    ])->assertOk();

    expect($response->json('data.subject'))->toBe('Hi Ada')
        ->and($response->json('data.html'))->toContain('Hi Ada Lovelace')
        ->and($response->json('data.html'))->not->toContain('<script')
        ->and($response->json('data.text'))->toContain('Hi Ada Lovelace')
        ->and($response->json('data.unknown_variables'))->toBe(['ghost']);

    // A half-written template still previews.
    $empty = $this->postJson('/api/v1/email-templates/preview', [])->assertOk();
    expect($empty->json('data.subject'))->toBe('')
        ->and($empty->json('data.unknown_variables'))->toBe([]);

    expect(DB::table('email_templates')->count())->toBe(0);
});

it('serves the variable catalog and limits the editor renders from', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(tmplapiUser($tenant, 'viewer'));

    $response = $this->getJson('/api/v1/email-templates/variables')->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['first_name', 'name', 'email', 'job_title'])
        ->and($response->json('limits'))->toBe(['name' => 160, 'subject' => 200, 'body' => 50000]);
});

it('re-checks permission and validity inside the action, independent of the request layer', function () {
    $tenant = Tenant::factory()->create();
    $viewer = tmplapiUser($tenant, 'viewer');
    $admin = tmplapiUser($tenant, 'admin');

    expect(fn () => (new CreateEmailTemplateAction())($viewer, tmplapiPayload()))
        ->toThrow(AuthorizationException::class);

    expect(fn () => (new CreateEmailTemplateAction())($admin, tmplapiPayload(['body' => '{{ ghost }}'])))
        ->toThrow(ValidationException::class);

    expect(DB::table('email_templates')->count())->toBe(0);
});
'@

Write-Host ''
Write-Host 'Done: tests written, 4 files patched. Next: php artisan test --filter=EmailTemplateApi ; php artisan test'
Write-Host 'Referenced but NOT shown to me (untouched): nothing else. If a test fails, send me the failure output before changing anything.'
