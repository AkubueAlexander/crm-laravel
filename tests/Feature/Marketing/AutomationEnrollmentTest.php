<?php

use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Marketing\Automations\AutomationEnroller;
use App\Domain\Marketing\Sending\EmailSuppressions;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Jobs\EnrollForTriggerJob;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/** @return array{0: Tenant, 1: User} */
function autmeSetup(string $role = 'admin'): array
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$tenant, $user];
}

function autmeAutomation(Tenant $tenant, string $status, string $trigger): int
{
    return DB::table('automations')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Auto '.uniqid('', true),
        'status' => $status,
        'trigger_type' => $trigger,
        'trigger_config' => '{}',
        'lock_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('enrolls each contact once and skips suppressed, invalid and foreign contacts', function () {
    [$tenant] = autmeSetup();
    $automationId = autmeAutomation($tenant, 'active', 'manual');

    $a = Contact::factory()->create(['email' => 'a@example.test']);
    $b = Contact::factory()->create(['email' => 'b@example.test']);
    $c = Contact::factory()->create(['email' => 'not-an-email']);

    $other = Tenant::factory()->create();
    TenantContext::set($other);
    $foreign = Contact::factory()->create(['email' => 'f@example.test']);
    TenantContext::set($tenant);

    EmailSuppressions::suppress($tenant->id, 'B@example.test', 'manual');

    $ids = [$a->id, $b->id, $c->id, $foreign->id];

    expect(AutomationEnroller::enrollContacts($tenant->id, $automationId, $ids))->toEqual([
        'enrolled' => 1, 'already_enrolled' => 0, 'skipped_suppressed' => 1, 'skipped_invalid' => 1,
    ]);

    expect(AutomationEnroller::enrollContacts($tenant->id, $automationId, $ids))->toEqual([
        'enrolled' => 0, 'already_enrolled' => 1, 'skipped_suppressed' => 1, 'skipped_invalid' => 1,
    ]);

    $rows = DB::table('automation_enrollments')->where('automation_id', $automationId)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->contact_id)->toBe($a->id);
    expect($rows[0]->status)->toBe('active');
    expect($rows[0]->email_normalized)->toBe('a@example.test');
    expect($rows[0]->next_run_at)->not->toBeNull();
    expect($rows[0]->current_step)->toBeNull();
});

it('enrolls nobody into a draft or paused automation', function () {
    [$tenant] = autmeSetup();
    $draft = autmeAutomation($tenant, 'draft', 'manual');
    $paused = autmeAutomation($tenant, 'paused', 'manual');
    $contact = Contact::factory()->create(['email' => 'x@example.test']);

    expect(AutomationEnroller::enrollContacts($tenant->id, $draft, [$contact->id])['enrolled'])->toBe(0);
    expect(AutomationEnroller::enrollContacts($tenant->id, $paused, [$contact->id])['enrolled'])->toBe(0);
    expect(DB::table('automation_enrollments')->count())->toBe(0);
});

it('queues enrollment on contact creation only when an active contact_created automation exists', function () {
    Queue::fake();
    [$tenant, $user] = autmeSetup();

    autmeAutomation($tenant, 'draft', 'contact_created');
    app(CreateContactAction::class)->execute($user, ['first_name' => 'No', 'last_name' => 'Listener', 'email' => 'none@example.test']);
    Queue::assertNotPushed(EnrollForTriggerJob::class);

    $automationId = autmeAutomation($tenant, 'active', 'contact_created');
    $contact = app(CreateContactAction::class)->execute($user, ['first_name' => 'New', 'last_name' => 'Person', 'email' => 'new@example.test']);

    Queue::assertPushed(EnrollForTriggerJob::class, fn (EnrollForTriggerJob $j) => $j->contactId === $contact->id && $j->trigger === 'contact_created');

    (new EnrollForTriggerJob($tenant->id, 'contact_created', $contact->id))->handle();

    expect(DB::table('automation_enrollments')
        ->where('automation_id', $automationId)
        ->where('contact_id', $contact->id)
        ->where('email_normalized', 'new@example.test')
        ->exists())->toBeTrue();
});

it('queues enrollment on lead creation and enrolls the lead by email', function () {
    Queue::fake();
    [$tenant, $user] = autmeSetup();
    $automationId = autmeAutomation($tenant, 'active', 'lead_created');

    $lead = app(CreateLeadAction::class)->execute($user, [
        'first_name' => 'Lena', 'last_name' => 'Lead', 'email' => 'lena@example.test', 'company' => 'Lead Co',
    ]);

    Queue::assertPushed(EnrollForTriggerJob::class, fn (EnrollForTriggerJob $j) => $j->leadId === $lead->id && $j->trigger === 'lead_created');

    (new EnrollForTriggerJob($tenant->id, 'lead_created', null, $lead->id))->handle();

    $row = DB::table('automation_enrollments')->where('automation_id', $automationId)->first();
    expect($row)->not->toBeNull();
    expect($row->lead_id)->toBe($lead->id);
    expect($row->contact_id)->toBeNull();
    expect($row->email_normalized)->toBe('lena@example.test');
});

it('enrolls contacts manually through the API and reports the counts', function () {
    [$tenant, $user] = autmeSetup('admin');
    Sanctum::actingAs($user);
    $active = autmeAutomation($tenant, 'active', 'manual');
    $draft = autmeAutomation($tenant, 'draft', 'manual');
    $contact = Contact::factory()->create(['email' => 'x@example.test']);

    $this->postJson("/api/v1/automations/{$active}/enroll", ['contact_ids' => [$contact->id]])
        ->assertOk()
        ->assertJson(['data' => ['enrolled' => 1, 'already_enrolled' => 0, 'skipped_suppressed' => 0, 'skipped_invalid' => 0]]);

    $this->postJson("/api/v1/automations/{$active}/enroll", ['contact_ids' => [$contact->id]])
        ->assertOk()
        ->assertJson(['data' => ['enrolled' => 0, 'already_enrolled' => 1]]);

    $this->postJson("/api/v1/automations/{$draft}/enroll", ['contact_ids' => [$contact->id]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['automation']]);

    $this->postJson("/api/v1/automations/{$active}/enroll", ['contact_ids' => []])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['contact_ids']]);
});

it('forbids read-only roles and hides other tenants automations', function () {
    [$tenant, $sales] = autmeSetup('sales');
    $automationId = autmeAutomation($tenant, 'active', 'manual');
    $contact = Contact::factory()->create(['email' => 'y@example.test']);

    Sanctum::actingAs($sales);
    $this->postJson("/api/v1/automations/{$automationId}/enroll", ['contact_ids' => [$contact->id]])
        ->assertForbidden();

    [$tenantB, $adminB] = autmeSetup('admin');
    Sanctum::actingAs($adminB);
    $this->postJson("/api/v1/automations/{$automationId}/enroll", ['contact_ids' => [1]])
        ->assertNotFound();
    expect(DB::table('automation_enrollments')->count())->toBe(0);
});