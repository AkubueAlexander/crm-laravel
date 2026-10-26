<?php

use App\Domain\Marketing\Automations\AutomationEnroller;
use App\Domain\Marketing\Automations\AutomationStepRunner;
use App\Domain\Marketing\Sending\EmailSuppressions;
use App\Domain\Tenant\TenantContext;
use App\Jobs\RunAutomationEnrollmentJob;
use App\Mail\CampaignEmail;
use App\Models\Contact;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

afterEach(fn () => Carbon::setTestNow());

/**
 * Active automation: email, wait 2 days, email. One enrolled contact (Ada). positions 0, 1, 2.
 *
 * @return array{tenant: Tenant, automation: int, template: int, enrollment: int}
 */
function autmrunSetup(): array
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);

    $template = DB::table('email_templates')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'T '.uniqid('', true),
        'subject' => 'Hi {{ first_name }}',
        'body' => 'Hello {{ name }}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $automation = DB::table('automations')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Auto '.uniqid('', true),
        'status' => 'active',
        'trigger_type' => 'manual',
        'trigger_config' => '{}',
        'lock_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $steps = [
        ['send_email', ['email_template_id' => $template]],
        ['delay', ['amount' => 2, 'unit' => 'days']],
        ['send_email', ['email_template_id' => $template]],
    ];
    foreach ($steps as $position => [$type, $config]) {
        DB::table('automation_steps')->insert([
            'tenant_id' => $tenant->id,
            'automation_id' => $automation,
            'position' => $position,
            'type' => $type,
            'config' => json_encode($config),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $contact = Contact::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
    AutomationEnroller::enrollContacts($tenant->id, $automation, [$contact->id]);

    return [
        'tenant' => $tenant,
        'automation' => $automation,
        'template' => $template,
        'enrollment' => (int) DB::table('automation_enrollments')->where('automation_id', $automation)->value('id'),
    ];
}

it('sends the first email, waits, then sends the second and completes', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-26 10:00:00');
    $s = autmrunSetup();

    expect(AutomationStepRunner::advance($s['tenant']->id, $s['enrollment'], null))->toBe('waiting');

    Mail::assertSent(CampaignEmail::class, 1);
    Mail::assertSent(CampaignEmail::class, fn (CampaignEmail $m) => $m->subjectLine === 'Hi Ada'
        && str_contains($m->extraHeaders['List-Unsubscribe'] ?? '', '/unsubscribe/'));

    $row = DB::table('automation_enrollments')->find($s['enrollment']);
    expect((int) $row->current_step)->toBe(1);
    expect($row->status)->toBe('active');
    expect(Carbon::parse($row->next_run_at)->toDateTimeString())->toBe('2026-10-28 10:00:00');

    Carbon::setTestNow('2026-10-28 10:00:01');
    expect(AutomationStepRunner::advance($s['tenant']->id, $s['enrollment'], 1))->toBe('completed');

    Mail::assertSent(CampaignEmail::class, 2);
    $row = DB::table('automation_enrollments')->find($s['enrollment']);
    expect($row->status)->toBe('completed');
    expect($row->completed_at)->not->toBeNull();
    expect($row->next_run_at)->toBeNull();
});

it('does nothing for a stale or finished run (duplicate or retried job)', function () {
    Mail::fake();
    $s = autmrunSetup();

    expect(AutomationStepRunner::advance($s['tenant']->id, $s['enrollment'], 5))->toBe('stale');
    Mail::assertNothingSent();

    AutomationStepRunner::advance($s['tenant']->id, $s['enrollment'], null);
    Mail::assertSent(CampaignEmail::class, 1);

    // The same job again, still carrying the old step.
    expect(AutomationStepRunner::advance($s['tenant']->id, $s['enrollment'], null))->toBe('stale');
    Mail::assertSent(CampaignEmail::class, 1);
});

it('stops sending while the automation is paused and resumes when it is active again', function () {
    Mail::fake();
    $s = autmrunSetup();
    DB::table('automations')->where('id', $s['automation'])->update(['status' => 'paused']);

    expect(AutomationStepRunner::advance($s['tenant']->id, $s['enrollment'], null))->toBe('paused');
    Mail::assertNothingSent();
    expect(DB::table('automation_enrollments')->find($s['enrollment'])->status)->toBe('active');

    DB::table('automations')->where('id', $s['automation'])->update(['status' => 'active']);
    expect(AutomationStepRunner::advance($s['tenant']->id, $s['enrollment'], null))->toBe('waiting');
    Mail::assertSent(CampaignEmail::class, 1);
});

it('fails the enrollment cleanly when the template is gone, and cancels it when the address is suppressed', function () {
    Mail::fake();

    $gone = autmrunSetup();
    DB::table('email_templates')->where('id', $gone['template'])->delete();
    expect(AutomationStepRunner::advance($gone['tenant']->id, $gone['enrollment'], null))->toBe('failed');
    $row = DB::table('automation_enrollments')->find($gone['enrollment']);
    expect($row->status)->toBe('failed');
    expect($row->last_error)->toContain('template');
    expect($row->next_run_at)->toBeNull();

    $suppressed = autmrunSetup();
    EmailSuppressions::suppress($suppressed['tenant']->id, 'ADA@example.test', 'unsubscribed');
    expect(AutomationStepRunner::advance($suppressed['tenant']->id, $suppressed['enrollment'], null))->toBe('cancelled');
    expect(DB::table('automation_enrollments')->find($suppressed['enrollment'])->status)->toBe('cancelled');

    Mail::assertNothingSent();
});

it('queues each due enrollment of an active automation exactly once and leases it', function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-26 10:00:00');

    $active = autmrunSetup();
    $paused = autmrunSetup();
    DB::table('automations')->where('id', $paused['automation'])->update(['status' => 'paused']);
    $later = autmrunSetup();
    DB::table('automation_enrollments')->where('id', $later['enrollment'])->update(['next_run_at' => '2026-10-26 12:00:00']);

    $this->artisan('automations:run-due')->assertSuccessful();

    Queue::assertPushed(RunAutomationEnrollmentJob::class, 1);
    Queue::assertPushed(RunAutomationEnrollmentJob::class, fn (RunAutomationEnrollmentJob $j) => $j->enrollmentId === $active['enrollment']
        && $j->tenantId === $active['tenant']->id
        && $j->expectedStep === null);

    expect(Carbon::parse(DB::table('automation_enrollments')->find($active['enrollment'])->next_run_at)->toDateTimeString())
        ->toBe('2026-10-26 10:10:00');

    // A second run while the lease holds queues nothing more.
    $this->artisan('automations:run-due')->assertSuccessful();
    Queue::assertPushed(RunAutomationEnrollmentJob::class, 1);
});

it('runs the steps when the queued job is handled', function () {
    Mail::fake();
    $s = autmrunSetup();

    (new RunAutomationEnrollmentJob($s['tenant']->id, $s['enrollment'], null))->handle();

    Mail::assertSent(CampaignEmail::class, 1);
    expect((int) DB::table('automation_enrollments')->find($s['enrollment'])->current_step)->toBe(1);
});