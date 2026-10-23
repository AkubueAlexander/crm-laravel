<?php

use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\Import\ContactCsvReader;
use App\Domain\Contacts\Matching\DuplicateDetectionService;
use App\Domain\Tenant\TenantContext;
use App\Events\ContactImportProgress;
use App\Jobs\ImportContactsJob;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function cimpTenant(string $slug)
{
    $class = class_exists(\App\Models\Tenant::class)
        ? \App\Models\Tenant::class
        : \App\Domain\Tenant\Tenant::class;

    return $class::create(['name' => $slug, 'slug' => $slug]);
}

function cimpSwitch($tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function cimpUser($tenant, bool $canCreate = true): User
{
    cimpSwitch($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    if ($canCreate) {
        Permission::findOrCreate('contacts.create');
        $user->givePermissionTo('contacts.create');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param list<list<string>> $rows */
function cimpCsv(array $rows): string
{
    $lines = ['first_name,last_name,email,phone'];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    return implode("\n", $lines)."\n";
}

function cimpUpload(User $user, string $csv, string $name = 'contacts.csv')
{
    Sanctum::actingAs($user);

    return test()->post(
        '/api/v1/contacts/imports',
        ['file' => UploadedFile::fake()->createWithContent($name, $csv)],
        ['Accept' => 'application/json'],
    );
}

function cimpRun(int $importId): void
{
    (new ImportContactsJob($importId))->handle(
        app(CreateContactAction::class),
        app(DuplicateDetectionService::class),
    );
}

beforeEach(function () {
    config(['broadcasting.default' => 'null']);
    Storage::fake('local');
});

it('reads aliases, a BOM, semicolons and skips blank rows', function () {
    $path = tempnam(sys_get_temp_dir(), 'cimp');
    file_put_contents($path, "\xEF\xBB\xBFSurname;First Name;E-mail\nSmith;Jo;jo@example.com\n\n;;\nLee;;\n");

    $rows = iterator_to_array(ContactCsvReader::rows($path), false);
    unlink($path);

    expect($rows)->toHaveCount(2);
    expect($rows[0][0])->toBe(2);
    expect($rows[0][1])->toEqual(['last_name' => 'Smith', 'first_name' => 'Jo', 'email' => 'jo@example.com']);
    expect($rows[1][0])->toBe(5);
    expect($rows[1][1]['last_name'])->toBe('Lee');
    expect($rows[1][1]['first_name'])->toBeNull();
});

it('accepts an upload and queues the import', function () {
    Queue::fake();
    $user = cimpUser(cimpTenant('cimp-a'));

    cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('total_rows', 1)
        ->assertJsonPath('processed_rows', 0)
        ->assertJsonPath('created_rows', 0)
        ->assertJsonPath('duplicate_rows', 0)
        ->assertJsonPath('invalid_rows', 0);

    Queue::assertPushed(ImportContactsJob::class);
});

it('rejects a csv without a last name column before queueing', function () {
    Queue::fake();
    $user = cimpUser(cimpTenant('cimp-a'));

    cimpUpload($user, "email,phone\na@example.com,5551234567\n")
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    Queue::assertNothingPushed();
});

it('forbids users without contacts.create', function () {
    Queue::fake();
    $user = cimpUser(cimpTenant('cimp-a'), false);

    cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->assertForbidden();

    Queue::assertNothingPushed();
});

it('creates valid rows and skips invalid and duplicate rows with a report', function () {
    Queue::fake();
    $tenant = cimpTenant('cimp-a');
    $user = cimpUser($tenant);
    Contact::create(['first_name' => 'Jane', 'last_name' => 'Smith', 'email' => 'jane@example.com']);

    $id = cimpUpload($user, cimpCsv([
        ['Bob', 'Jones', 'bob@example.com', '555-123-4567'],
        ['', '', 'nolast@example.com', ''],
        ['Jane', 'Smith', 'jane@example.com', ''],
        ['Ann', 'Lee', '', ''],
    ]))->assertStatus(202)->json('id');

    cimpRun($id);
    cimpSwitch($tenant);

    $import = ContactImport::find($id);

    expect($import->status)->toBe('completed');
    expect($import->processed_rows)->toBe(4);
    expect($import->created_rows)->toBe(2);
    expect($import->invalid_rows)->toBe(1);
    expect($import->duplicate_rows)->toBe(1);
    expect(collect($import->report)->pluck('type')->sort()->values()->all())->toBe(['duplicate', 'invalid']);
    expect(Contact::count())->toBe(3);
    Storage::disk('local')->assertMissing($import->path);
});

it('is a no-op when the same import runs twice', function () {
    Queue::fake();
    $tenant = cimpTenant('cimp-a');
    $user = cimpUser($tenant);

    $id = cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->json('id');

    cimpRun($id);
    cimpRun($id);
    cimpSwitch($tenant);

    expect(Contact::count())->toBe(1);
    expect(ContactImport::find($id)->created_rows)->toBe(1);
});

it('does not treat another tenants contact as a duplicate', function () {
    Queue::fake();
    $a = cimpTenant('cimp-a');
    $b = cimpTenant('cimp-b');

    cimpSwitch($b);
    Contact::create(['first_name' => 'Zed', 'last_name' => 'Quill', 'email' => 'shared@example.com']);

    $user = cimpUser($a);
    $id = cimpUpload($user, cimpCsv([['Zed', 'Quill', 'shared@example.com', '']]))->json('id');

    cimpRun($id);
    cimpSwitch($a);

    expect(ContactImport::find($id)->created_rows)->toBe(1);
    expect(ContactImport::find($id)->duplicate_rows)->toBe(0);
    expect(Contact::count())->toBe(1);
});

it('shows an import only to the user who started it', function () {
    Queue::fake();
    $a = cimpTenant('cimp-a');
    $b = cimpTenant('cimp-b');
    $owner = cimpUser($a);
    $colleague = cimpUser($a);
    $stranger = cimpUser($b);

    $id = cimpUpload($owner, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->json('id');

    Sanctum::actingAs($owner);
    $this->getJson("/api/v1/contacts/imports/{$id}")->assertOk()->assertJsonPath('status', 'pending');

    Sanctum::actingAs($colleague);
    $this->getJson("/api/v1/contacts/imports/{$id}")->assertNotFound();

    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/contacts/imports/{$id}")->assertNotFound();
});

it('broadcasts progress on the tenant-scoped private user channel', function () {
    Queue::fake();
    $tenant = cimpTenant('cimp-a');
    $user = cimpUser($tenant);
    $id = cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->json('id');

    Event::fake([ContactImportProgress::class]);
    cimpRun($id);

    Event::assertDispatched(ContactImportProgress::class, function ($event) use ($tenant, $user, $id) {
        $names = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

        return $event->payload['status'] === 'completed'
            && $event->payload['id'] === $id
            && in_array("private-tenant.{$tenant->id}.user.{$user->id}", $names, true);
    });
});

it('fails cleanly when the permission was revoked while queued', function () {
    Queue::fake();
    $tenant = cimpTenant('cimp-a');
    $user = cimpUser($tenant);
    $id = cimpUpload($user, cimpCsv([['Bob', 'Jones', 'bob@example.com', '']]))->json('id');

    cimpSwitch($tenant);
    $user->revokePermissionTo('contacts.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    cimpRun($id);
    cimpSwitch($tenant);

    $import = ContactImport::find($id);
    expect($import->status)->toBe('failed');
    expect($import->error)->toContain('permission');
    expect(Contact::count())->toBe(0);
});
