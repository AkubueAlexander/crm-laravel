<?php

use App\Domain\Marketing\Automations\AutomationEnroller;
use App\Domain\Marketing\Sending\EmailSuppressions;
use App\Domain\Marketing\Sending\EnrollmentUnsubscribeToken;
use App\Domain\Marketing\Sending\UnsubscribeToken;
use App\Domain\Tenant\TenantContext;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

function autmscanTenant(): Tenant
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);

    return $tenant;
}

function autmscanAutomation(Tenant $tenant, string $status, string $trigger, array $config = []): int
{
    return DB::table('automations')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Auto '.uniqid('', true),
        'status' => $status,
        'trigger_type' => $trigger,
        'trigger_config' => json_encode((object) $config),
        'lock_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function autmscanSegment(Tenant $tenant): int
{
    // No conditions: matches every contact of the tenant.
    return DB::table('segments')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'All '.uniqid('', true),
        'match_mode' => 'all',
        'conditions' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('enrolls the contacts matching the segment of an active segment_entered automation, once', function () {
    $tenant = autmscanTenant();
    $segment = autmscanSegment($tenant);
    $active = autmscanAutomation($tenant, 'active', 'segment_entered', ['segment_id' => $segment]);
    $draft = autmscanAutomation($tenant, 'draft', 'segment_entered', ['segment_id' => $segment]);

    $a = Contact::factory()->create(['email' => 'a@example.test']);
    $b = Contact::factory()->create(['email' => 'b@example.test']);
    Contact::factory()->create(['email' => 'not-an-email']);

    $other = autmscanTenant();
    Contact::factory()->create(['email' => 'other@example.test']);
    TenantContext::set($tenant);

    $this->artisan('automations:scan-segments')->assertSuccessful();

    $ids = DB::table('automation_enrollments')->where('automation_id', $active)->orderBy('contact_id')->pluck('contact_id')->map(fn ($i) => (int) $i)->all();
    expect($ids)->toBe([$a->id, $b->id]);
    expect(DB::table('automation_enrollments')->where('automation_id', $draft)->count())->toBe(0);
    expect(DB::table('automation_enrollments')->where('tenant_id', $other->id)->count())->toBe(0);

    // A later scan enrolls only newly matching contacts.
    // The command clears the tenant context when it finishes, as a queue job would.
    TenantContext::set($tenant);
    $c = Contact::factory()->create(['email' => 'c@example.test']);
    $this->artisan('automations:scan-segments')->assertSuccessful();
    expect(DB::table('automation_enrollments')->where('automation_id', $active)->count())->toBe(3);
    expect(DB::table('automation_enrollments')->where('automation_id', $active)->where('contact_id', $c->id)->exists())->toBeTrue();
});

it('unsubscribes through an enrollment token and keeps campaign and enrollment tokens apart', function () {
    $tenant = autmscanTenant();
    $automation = autmscanAutomation($tenant, 'active', 'manual');
    $contact = Contact::factory()->create(['email' => 'ada@example.test']);
    AutomationEnroller::enrollContacts($tenant->id, $automation, [$contact->id]);
    $enrollment = (int) DB::table('automation_enrollments')->where('automation_id', $automation)->value('id');

    $token = EnrollmentUnsubscribeToken::make($tenant->id, $enrollment);

    expect(EnrollmentUnsubscribeToken::parse($token))->toBe(['tenant_id' => $tenant->id, 'enrollment_id' => $enrollment]);
    expect(UnsubscribeToken::parse($token))->toBeNull();
    expect(EnrollmentUnsubscribeToken::parse(UnsubscribeToken::make($tenant->id, $enrollment)))->toBeNull();

    // GET only shows the page and changes nothing.
    $this->get('/unsubscribe/'.$token)->assertOk()->assertSee('a***@example.test', false);
    expect(EmailSuppressions::isSuppressed($tenant->id, 'ada@example.test'))->toBeFalse();

    $this->post('/unsubscribe/'.$token)->assertOk();
    expect(EmailSuppressions::isSuppressed($tenant->id, 'ada@example.test'))->toBeTrue();

    // Tampered token (first character flipped): the same 404 as any unknown link.
    $tampered = ($token[0] === 'A' ? 'B' : 'A').substr($token, 1);
    $this->get('/unsubscribe/'.$tampered)->assertNotFound();
    $this->post('/unsubscribe/'.$tampered)->assertNotFound();
});