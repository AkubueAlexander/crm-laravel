<?php

use App\Domain\Contacts\Export\ContactsExport;
use App\Domain\Tenant\TenantContext;
use App\Events\ContactExportProgress;
use App\Jobs\ExportContactsJob;
use App\Models\Contact;
use App\Models\ContactExport;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function cexpTenant(string $slug)
{
    $class = class_exists(\App\Models\Tenant::class)
        ? \App\Models\Tenant::class
        : \App\Domain\Tenant\Tenant::class;

    return $class::create(['name' => $slug, 'slug' => $slug]);
}

function cexpSwitch($tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function cexpUser($tenant, bool $canView = true): User
{
    cexpSwitch($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    if ($canView) {
        Permission::findOrCreate('contacts.view');
        $user->givePermissionTo('contacts.view');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param array<string, mixed> $attrs */
function cexpContact(array $attrs = []): Contact
{
    return Contact::create(array_merge(['first_name' => 'Ann', 'last_name' => 'Smith'], $attrs));
}

/** @param array<string, mixed> $payload */
function cexpPost(User $user, array $payload)
{
    Sanctum::actingAs($user);

    return test()->postJson('/api/v1/contacts/exports', $payload);
}

function cexpRun(int $id): void
{
    (new ExportContactsJob($id))->handle();
}

/** @return list<list<string|null>> */
function cexpRows(string $path): array
{
    $content = Storage::disk('local')->get($path);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    return array_map('str_getcsv', preg_split('/\r\n|\n/', trim($content)));
}

beforeEach(function () {
    config(['broadcasting.default' => 'null']);
    Storage::fake('local');
});

it('accepts a request, returns the full resource shape and queues the job', function () {
    Queue::fake();
    $user = cexpUser(cexpTenant('cexp-a'));
    cexpContact();

    cexpPost($user, ['format' => 'csv'])
        ->assertStatus(202)
        ->assertJsonStructure([
            'id', 'format', 'filters' => ['q', 'sort', 'direction'], 'status', 'row_count', 'error',
            'expired', 'download_url', 'expires_at', 'started_at', 'finished_at', 'created_at',
        ])
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('format', 'csv')
        ->assertJsonPath('row_count', 1)
        ->assertJsonPath('filters.sort', 'last_name')
        ->assertJsonPath('filters.direction', 'asc')
        ->assertJsonPath('expired', false)
        ->assertJsonPath('download_url', null);

    Queue::assertPushed(ExportContactsJob::class);
});

it('rejects an unknown format and a non-whitelisted sort column', function () {
    Queue::fake();
    $user = cexpUser(cexpTenant('cexp-a'));
    cexpContact();

    cexpPost($user, ['format' => 'pdf'])->assertStatus(422)->assertJsonValidationErrors('format');
    cexpPost($user, ['format' => 'csv', 'sort' => 'name_normalized; drop table contacts'])
        ->assertStatus(422)->assertJsonValidationErrors('sort');

    Queue::assertNothingPushed();
});

it('forbids users without contacts.view', function () {
    Queue::fake();
    $user = cexpUser(cexpTenant('cexp-a'), false);

    cexpPost($user, ['format' => 'csv'])->assertForbidden();

    Queue::assertNothingPushed();
});

it('refuses an empty export and one over the row limit', function () {
    Queue::fake();
    $user = cexpUser(cexpTenant('cexp-a'));

    cexpPost($user, ['format' => 'csv'])->assertStatus(422)->assertJsonValidationErrors('q');

    cexpContact(['last_name' => 'One']);
    cexpContact(['last_name' => 'Two']);
    cexpContact(['last_name' => 'Three']);
    config(['crm.contact_export_max_rows' => 2]);

    cexpPost($user, ['format' => 'csv'])->assertStatus(422)->assertJsonValidationErrors('q');

    Queue::assertNothingPushed();
});

it('writes a csv that honors the search and sort of the list', function () {
    Queue::fake();
    $tenant = cexpTenant('cexp-a');
    $user = cexpUser($tenant);
    cexpContact(['first_name' => 'Ann', 'last_name' => 'Smith', 'email' => 'ann@example.com']);
    cexpContact(['first_name' => 'Bob', 'last_name' => 'Jones', 'email' => 'bob@example.com']);
    cexpContact(['first_name' => 'Cy', 'last_name' => 'Smithers', 'email' => 'cy@example.com']);

    $id = cexpPost($user, ['format' => 'csv', 'q' => 'smith', 'sort' => 'last_name', 'direction' => 'desc'])
        ->assertStatus(202)->json('id');

    cexpRun($id);
    cexpSwitch($tenant);

    $export = ContactExport::find($id);

    expect($export->status)->toBe('completed');
    expect($export->row_count)->toBe(2);
    expect($export->path)->not->toBeNull();
    expect($export->expires_at->isFuture())->toBeTrue();
    Storage::disk('local')->assertExists($export->path);

    $rows = cexpRows($export->path);
    expect($rows)->toHaveCount(3);
    expect($rows[0][0])->toBe('Last name');
    expect($rows[1][0])->toBe('Smithers');
    expect($rows[2][0])->toBe('Smith');
    expect(Storage::disk('local')->get($export->path))->not->toContain('Jones');
    // The BOM is what makes Excel read non-ASCII names correctly.
    expect(str_starts_with(Storage::disk('local')->get($export->path), "\xEF\xBB\xBF"))->toBeTrue();
});

it('writes an xlsx that opens with the same rows', function () {
    Queue::fake();
    $tenant = cexpTenant('cexp-a');
    $user = cexpUser($tenant);
    cexpContact(['first_name' => 'Ann', 'last_name' => 'Smith']);

    $id = cexpPost($user, ['format' => 'xlsx'])->assertStatus(202)->json('id');

    cexpRun($id);
    cexpSwitch($tenant);

    $export = ContactExport::find($id);
    expect($export->status)->toBe('completed');

    $path = Storage::disk('local')->path($export->path);
    expect(str_starts_with(file_get_contents($path), 'PK'))->toBeTrue();

    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
    expect($sheet->getCell('A1')->getValue())->toBe('Last name');
    expect($sheet->getCell('A2')->getValue())->toBe('Smith');
});

it('neutralizes spreadsheet formulas but keeps international phone numbers intact', function () {
    Queue::fake();
    $tenant = cexpTenant('cexp-a');
    $user = cexpUser($tenant);
    cexpContact(['last_name' => '=1+1', 'job_title' => '@SUM(1)', 'phone' => '+2348012345678']);

    $id = cexpPost($user, ['format' => 'csv'])->json('id');
    cexpRun($id);
    cexpSwitch($tenant);

    $rows = cexpRows(ContactExport::find($id)->path);

    expect($rows[1][0])->toBe("'=1+1");
    expect($rows[1][3])->toBe('+2348012345678');
    expect($rows[1][4])->toBe("'@SUM(1)");
    expect(ContactsExport::safe('+1+1', true))->toBe("'+1+1");
});

it('never exports another tenants contacts', function () {
    Queue::fake();
    $a = cexpTenant('cexp-a');
    $b = cexpTenant('cexp-b');

    cexpSwitch($b);
    cexpContact(['first_name' => 'Zed', 'last_name' => 'Quill']);

    $user = cexpUser($a);
    cexpContact(['first_name' => 'Ann', 'last_name' => 'Smith']);

    $id = cexpPost($user, ['format' => 'csv'])->json('id');
    cexpRun($id);
    cexpSwitch($a);

    $export = ContactExport::find($id);
    expect($export->row_count)->toBe(1);
    expect(Storage::disk('local')->get($export->path))->not->toContain('Quill');
});

it('is a no-op when the same export runs twice', function () {
    Queue::fake();
    $tenant = cexpTenant('cexp-a');
    $user = cexpUser($tenant);
    cexpContact();

    $id = cexpPost($user, ['format' => 'csv'])->json('id');
    cexpRun($id);
    cexpSwitch($tenant);
    $first = ContactExport::find($id)->path;

    cexpRun($id);
    cexpSwitch($tenant);

    expect(ContactExport::find($id)->path)->toBe($first);
    expect(Storage::disk('local')->allFiles('contact-exports'))->toHaveCount(1);
});

it('broadcasts progress on the tenant-scoped private user channel', function () {
    Queue::fake();
    $tenant = cexpTenant('cexp-a');
    $user = cexpUser($tenant);
    cexpContact();
    $id = cexpPost($user, ['format' => 'csv'])->json('id');

    Event::fake([ContactExportProgress::class]);
    cexpRun($id);

    Event::assertDispatched(ContactExportProgress::class, function ($event) use ($tenant, $user, $id) {
        $names = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

        return $event->payload['status'] === 'completed'
            && $event->payload['id'] === $id
            && in_array("private-tenant.{$tenant->id}.user.{$user->id}", $names, true);
    });
});

it('fails cleanly and writes no file when the permission was revoked while queued', function () {
    Queue::fake();
    $tenant = cexpTenant('cexp-a');
    $user = cexpUser($tenant);
    cexpContact();
    $id = cexpPost($user, ['format' => 'csv'])->json('id');

    cexpSwitch($tenant);
    $user->revokePermissionTo('contacts.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    cexpRun($id);
    cexpSwitch($tenant);

    $export = ContactExport::find($id);
    expect($export->status)->toBe('failed');
    expect($export->error)->toContain('permission');
    expect($export->path)->toBeNull();
    expect(Storage::disk('local')->allFiles('contact-exports'))->toBeEmpty();
});

it('serves the file only through a signed url to the user who started it', function () {
    Queue::fake();
    $a = cexpTenant('cexp-a');
    $b = cexpTenant('cexp-b');
    $owner = cexpUser($a);
    $colleague = cexpUser($a);
    $stranger = cexpUser($b);
    // The last cexpUser() call switched context to tenant b; this contact belongs to tenant a.
    cexpSwitch($a);
    cexpContact();

    $id = cexpPost($owner, ['format' => 'csv'])->json('id');
    cexpRun($id);

    Sanctum::actingAs($owner);
    $show = test()->getJson("/api/v1/contacts/exports/{$id}")
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('expired', false);
    $url = $show->json('download_url');

    expect($url)->toStartWith("/api/v1/contacts/exports/{$id}/download");

    $download = test()->get($url)->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('attachment');

    // No signature: refused before anything else is checked.
    test()->getJson("/api/v1/contacts/exports/{$id}/download")->assertForbidden();

    // A valid link is still private to its owner.
    Sanctum::actingAs($colleague);
    test()->getJson("/api/v1/contacts/exports/{$id}")->assertNotFound();
    test()->get($url)->assertNotFound();

    Sanctum::actingAs($stranger);
    test()->getJson("/api/v1/contacts/exports/{$id}")->assertNotFound();
    test()->get($url)->assertNotFound();
});

it('reports an expired export and refuses its download with 410', function () {
    Queue::fake();
    $tenant = cexpTenant('cexp-a');
    $user = cexpUser($tenant);
    cexpContact();
    $id = cexpPost($user, ['format' => 'csv'])->json('id');
    cexpRun($id);

    ContactExport::withoutTenantScope()->whereKey($id)->update(['expires_at' => now()->subMinute()]);

    Sanctum::actingAs($user);
    test()->getJson("/api/v1/contacts/exports/{$id}")
        ->assertOk()
        ->assertJsonPath('expired', true)
        ->assertJsonPath('download_url', null);

    $signed = URL::temporarySignedRoute('contacts.exports.download', now()->addMinutes(5), ['export' => $id], absolute: false);
    test()->get($signed)->assertStatus(410);
});

it('prunes expired files but keeps live ones', function () {
    $tenant = cexpTenant('cexp-a');
    $user = cexpUser($tenant);

    Storage::disk('local')->put('contact-exports/x/old.csv', 'old');
    Storage::disk('local')->put('contact-exports/x/new.csv', 'new');

    $old = ContactExport::create([
        'user_id' => $user->id, 'format' => 'csv', 'filters' => [], 'status' => 'completed',
        'path' => 'contact-exports/x/old.csv', 'expires_at' => now()->subHour(),
    ]);
    $new = ContactExport::create([
        'user_id' => $user->id, 'format' => 'csv', 'filters' => [], 'status' => 'completed',
        'path' => 'contact-exports/x/new.csv', 'expires_at' => now()->addHour(),
    ]);

    test()->artisan('contacts:prune-exports')->assertExitCode(0);

    Storage::disk('local')->assertMissing('contact-exports/x/old.csv');
    Storage::disk('local')->assertExists('contact-exports/x/new.csv');
    expect(ContactExport::withoutTenantScope()->find($old->id)->path)->toBeNull();
    expect(ContactExport::withoutTenantScope()->find($new->id)->path)->toBe('contact-exports/x/new.csv');
});

it('keeps numeric-looking phone numbers as text in csv and xlsx', function () {
    Queue::fake();
    $tenant = cexpTenant('cexp-a');
    $user = cexpUser($tenant);
    cexpContact(['last_name' => 'A', 'phone' => '08012345678']);
    cexpContact(['last_name' => 'B', 'phone' => '2348012345678']);
    cexpContact(['last_name' => 'C', 'phone' => '+2348012345678']);

    $csvId = cexpPost($user, ['format' => 'csv'])->json('id');
    $xlsxId = cexpPost($user, ['format' => 'xlsx'])->json('id');
    cexpRun($csvId);
    cexpRun($xlsxId);
    cexpSwitch($tenant);

    $rows = cexpRows(ContactExport::find($csvId)->path);
    expect(array_column($rows, 3))->toBe(['Phone', '08012345678', '2348012345678', '+2348012345678']);

    $path = Storage::disk('local')->path(ContactExport::find($xlsxId)->path);
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

    foreach (['D2' => '08012345678', 'D3' => '2348012345678', 'D4' => '+2348012345678'] as $cell => $expected) {
        expect($sheet->getCell($cell)->getValue())->toBe($expected);
        expect($sheet->getCell($cell)->getDataType())->toBe('s');
    }
});
