<?php

use App\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Domain\Marketing\Sending\EmailSuppressions;
use App\Domain\Marketing\Sending\UnsubscribeToken;
use App\Jobs\SendCampaignChunkJob;
use App\Jobs\StartCampaignSendJob;
use App\Mail\CampaignEmail;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function cmpsendTenant(): Tenant
{
    Queue::fake();
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return $tenant;
}

function cmpsendManager(Tenant $tenant): User
{
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('manager');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function cmpsendContact(Tenant $tenant, string $email, string $first, string $last): void
{
    TenantContext::set($tenant);
    Contact::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'job_title' => null,
    ]);
}

/** A campaign with a segment (empty conditions = every contact) and a template. */
function cmpsendCampaign(int $tenantId, string $status = 'sending', array $overrides = []): int
{
    $segmentId = DB::table('segments')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Seg '.Str::random(8),
        'match_mode' => 'all',
        'conditions' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $templateId = DB::table('email_templates')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Tpl '.Str::random(8),
        'subject' => 'Hello {{ first_name }}',
        'body' => 'Hi {{ first_name }}, welcome.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('campaigns')->insertGetId(array_merge([
        'tenant_id' => $tenantId,
        'name' => 'Camp '.Str::random(8),
        'status' => $status,
        'lock_version' => 1,
        'segment_id' => $segmentId,
        'email_template_id' => $templateId,
        'started_at' => $status === 'sending' ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function cmpsendRecipient(int $tenantId, int $campaignId, string $email, ?string $name = null, string $status = 'pending'): int
{
    return DB::table('campaign_recipients')->insertGetId([
        'tenant_id' => $tenantId,
        'campaign_id' => $campaignId,
        'email' => $email,
        'email_normalized' => mb_strtolower($email),
        'name' => $name,
        'status' => $status,
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cmpsendCampaignRow(int $id): object
{
    return DB::table('campaigns')->where('id', $id)->first();
}

function cmpsendRecipientRow(int $id): object
{
    return DB::table('campaign_recipients')->where('id', $id)->first();
}

function cmpsendRunChunk(int $tenantId, int $campaignId): void
{
    app()->call([new SendCampaignChunkJob($tenantId, $campaignId), 'handle']);
}

function cmpsendRunStart(int $tenantId, int $campaignId): void
{
    app()->call([new StartCampaignSendJob($tenantId, $campaignId), 'handle']);
}

// ---------------------------------------------------------------- start job

it('freezes the audience when the start job runs and hands over to the chunk job', function () {
    $tenant = cmpsendTenant();
    cmpsendContact($tenant, 'ada@example.com', 'Ada', 'Lovelace');
    cmpsendContact($tenant, 'bob@example.com', 'Bob', 'Stone');
    $id = cmpsendCampaign($tenant->id);

    cmpsendRunStart($tenant->id, $id);

    expect(DB::table('campaign_recipients')->where('campaign_id', $id)->count())->toBe(2)
        ->and((int) cmpsendCampaignRow($id)->recipients_count)->toBe(2)
        ->and(cmpsendCampaignRow($id)->status)->toBe('sending');

    Queue::assertPushed(SendCampaignChunkJob::class, fn ($job) => $job->campaignId === $id && $job->tenantId === $tenant->id);
});

it('finishes a campaign with an empty audience instead of leaving it sending', function () {
    $tenant = cmpsendTenant();
    $id = cmpsendCampaign($tenant->id);

    cmpsendRunStart($tenant->id, $id);

    expect(cmpsendCampaignRow($id)->status)->toBe('sent')
        ->and(cmpsendCampaignRow($id)->completed_at)->not->toBeNull()
        ->and((int) cmpsendCampaignRow($id)->recipients_count)->toBe(0);

    Queue::assertNotPushed(SendCampaignChunkJob::class);
});

it('does nothing for a campaign that is no longer sending', function () {
    $tenant = cmpsendTenant();
    cmpsendContact($tenant, 'ada@example.com', 'Ada', 'Lovelace');
    $id = cmpsendCampaign($tenant->id, 'cancelled');

    cmpsendRunStart($tenant->id, $id);

    expect(DB::table('campaign_recipients')->where('campaign_id', $id)->count())->toBe(0)
        ->and(cmpsendCampaignRow($id)->status)->toBe('cancelled');

    Queue::assertNotPushed(SendCampaignChunkJob::class);
});

it('marks the campaign failed when it has no segment', function () {
    $tenant = cmpsendTenant();
    $id = cmpsendCampaign($tenant->id, 'sending', ['segment_id' => null]);

    cmpsendRunStart($tenant->id, $id);

    expect(cmpsendCampaignRow($id)->status)->toBe('failed');
});

it('puts failed recipients back to pending when a failed campaign is retried', function () {
    $tenant = cmpsendTenant();
    $id = cmpsendCampaign($tenant->id);
    cmpsendRecipient($tenant->id, $id, 'done@example.com', null, 'sent');
    $failedId = cmpsendRecipient($tenant->id, $id, 'retry@example.com', null, 'failed');

    cmpsendRunStart($tenant->id, $id);

    expect(cmpsendRecipientRow($failedId)->status)->toBe('pending')
        ->and((int) cmpsendCampaignRow($id)->failed_count)->toBe(0)
        ->and((int) cmpsendCampaignRow($id)->sent_count)->toBe(1);

    Queue::assertPushed(SendCampaignChunkJob::class);
});

// ---------------------------------------------------------------- chunk job

it('sends every pending recipient with a personal subject, an unsubscribe link and the headers, then completes the campaign', function () {
    $tenant = cmpsendTenant();
    Mail::fake();
    $id = cmpsendCampaign($tenant->id);
    $adaId = cmpsendRecipient($tenant->id, $id, 'ada@example.com', 'Ada Lovelace');
    $bobId = cmpsendRecipient($tenant->id, $id, 'bob@example.com', 'Bob Stone');

    cmpsendRunChunk($tenant->id, $id);

    Mail::assertSent(CampaignEmail::class, 2);
    Mail::assertSent(CampaignEmail::class, function (CampaignEmail $mail) use ($adaId, $tenant) {
        $token = UnsubscribeToken::make($tenant->id, $adaId);

        return $mail->hasTo('ada@example.com')
            && $mail->subjectLine === 'Hello Ada'
            && str_contains($mail->htmlBody, 'Hi Ada, welcome.')
            && str_contains($mail->htmlBody, '/unsubscribe/'.$token)
            && str_contains($mail->textBody, '/unsubscribe/'.$token)
            && isset($mail->extraHeaders['List-Unsubscribe'], $mail->extraHeaders['List-Unsubscribe-Post']);
    });

    expect(cmpsendRecipientRow($adaId)->status)->toBe('sent')
        ->and(cmpsendRecipientRow($adaId)->sent_at)->not->toBeNull()
        ->and((int) cmpsendRecipientRow($adaId)->attempts)->toBe(1)
        ->and(cmpsendRecipientRow($bobId)->status)->toBe('sent');

    $campaign = cmpsendCampaignRow($id);
    expect($campaign->status)->toBe('sent')
        ->and($campaign->sent_at)->not->toBeNull()
        ->and((int) $campaign->sent_count)->toBe(2)
        ->and((int) $campaign->recipients_count)->toBe(2)
        ->and((int) $campaign->failed_count)->toBe(0);
});

it('skips an address that unsubscribed after the snapshot was taken', function () {
    $tenant = cmpsendTenant();
    Mail::fake();
    $id = cmpsendCampaign($tenant->id);
    cmpsendRecipient($tenant->id, $id, 'stay@example.com');
    $leftId = cmpsendRecipient($tenant->id, $id, 'left@example.com');
    EmailSuppressions::suppress($tenant->id, 'LEFT@example.com', 'unsubscribed');

    cmpsendRunChunk($tenant->id, $id);

    Mail::assertSent(CampaignEmail::class, 1);
    Mail::assertSent(CampaignEmail::class, fn (CampaignEmail $m) => $m->hasTo('stay@example.com'));
    expect(cmpsendRecipientRow($leftId)->status)->toBe('skipped')
        ->and(cmpsendCampaignRow($id)->status)->toBe('sent')
        ->and((int) cmpsendCampaignRow($id)->sent_count)->toBe(1);
});

it('records one failing recipient without stopping the others', function () {
    $tenant = cmpsendTenant();
    Mail::shouldReceive('to')->andReturnUsing(function (string $email) {
        return new class($email)
        {
            public function __construct(private string $email)
            {
            }

            public function send($mailable): void
            {
                if ($this->email === 'bad@example.com') {
                    throw new RuntimeException('smtp down');
                }
            }
        };
    });
    $id = cmpsendCampaign($tenant->id);
    $goodId = cmpsendRecipient($tenant->id, $id, 'good@example.com');
    $badId = cmpsendRecipient($tenant->id, $id, 'bad@example.com');

    cmpsendRunChunk($tenant->id, $id);

    expect(cmpsendRecipientRow($goodId)->status)->toBe('sent')
        ->and(cmpsendRecipientRow($badId)->status)->toBe('failed')
        ->and(cmpsendRecipientRow($badId)->error)->toContain('smtp down')
        ->and(cmpsendCampaignRow($id)->status)->toBe('sent')
        ->and((int) cmpsendCampaignRow($id)->failed_count)->toBe(1)
        ->and((int) cmpsendCampaignRow($id)->sent_count)->toBe(1);
});

it('marks the campaign failed when every send fails', function () {
    $tenant = cmpsendTenant();
    Mail::shouldReceive('to')->andReturnUsing(function (string $email) {
        return new class
        {
            public function send($mailable): void
            {
                throw new RuntimeException('smtp down');
            }
        };
    });
    $id = cmpsendCampaign($tenant->id);
    cmpsendRecipient($tenant->id, $id, 'one@example.com');
    cmpsendRecipient($tenant->id, $id, 'two@example.com');

    cmpsendRunChunk($tenant->id, $id);

    expect(cmpsendCampaignRow($id)->status)->toBe('failed')
        ->and((int) cmpsendCampaignRow($id)->failed_count)->toBe(2)
        ->and((int) cmpsendCampaignRow($id)->sent_count)->toBe(0);
});

it('stops quietly when the campaign was cancelled', function () {
    $tenant = cmpsendTenant();
    Mail::fake();
    $id = cmpsendCampaign($tenant->id, 'cancelled');
    $recipientId = cmpsendRecipient($tenant->id, $id, 'ada@example.com');

    cmpsendRunChunk($tenant->id, $id);

    Mail::assertNothingSent();
    expect(cmpsendRecipientRow($recipientId)->status)->toBe('pending')
        ->and(cmpsendCampaignRow($id)->status)->toBe('cancelled');
});

it('sends in chunks: re-dispatches itself while recipients are pending, then finalizes', function () {
    $tenant = cmpsendTenant();
    Mail::fake();
    $id = cmpsendCampaign($tenant->id);
    for ($i = 1; $i <= SendCampaignChunkJob::CHUNK + 2; $i++) {
        cmpsendRecipient($tenant->id, $id, "person{$i}@example.com");
    }

    cmpsendRunChunk($tenant->id, $id);

    Mail::assertSent(CampaignEmail::class, SendCampaignChunkJob::CHUNK);
    expect(cmpsendCampaignRow($id)->status)->toBe('sending')
        ->and(DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->count())->toBe(2);
    Queue::assertPushed(SendCampaignChunkJob::class);

    cmpsendRunChunk($tenant->id, $id);

    Mail::assertSent(CampaignEmail::class, SendCampaignChunkJob::CHUNK + 2);
    expect(cmpsendCampaignRow($id)->status)->toBe('sent')
        ->and((int) cmpsendCampaignRow($id)->sent_count)->toBe(SendCampaignChunkJob::CHUNK + 2);
});

it('never touches another tenant\'s recipients', function () {
    $tenant = cmpsendTenant();
    $other = Tenant::factory()->create();
    Mail::fake();
    $id = cmpsendCampaign($tenant->id);
    $otherCampaign = cmpsendCampaign($other->id);
    $foreignId = cmpsendRecipient($other->id, $otherCampaign, 'foreign@example.com');

    cmpsendRunChunk($tenant->id, $id);

    Mail::assertNothingSent();
    expect(cmpsendRecipientRow($foreignId)->status)->toBe('pending');
});

// ---------------------------------------------------------------- hooks: transition endpoint and scheduler

it('dispatches the start job when a campaign is sent now', function () {
    $tenant = cmpsendTenant();
    $user = cmpsendManager($tenant);
    $id = cmpsendCampaign($tenant->id, 'draft', ['lock_version' => 0]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$id}/transition", ['to_status' => 'sending', 'lock_version' => 0])
        ->assertOk()
        ->assertJsonPath('status', 'sending');

    Queue::assertPushed(StartCampaignSendJob::class, fn ($job) => $job->campaignId === $id && $job->tenantId === $tenant->id);
});

it('starts due scheduled campaigns, leaves future ones alone and returns unstartable ones to draft', function () {
    $tenant = cmpsendTenant();
    $due = cmpsendCampaign($tenant->id, 'scheduled', ['scheduled_at' => now()->subMinute(), 'started_at' => null]);
    $future = cmpsendCampaign($tenant->id, 'scheduled', ['scheduled_at' => now()->addHour(), 'started_at' => null]);
    $broken = cmpsendCampaign($tenant->id, 'scheduled', ['scheduled_at' => now()->subMinute(), 'segment_id' => null, 'started_at' => null]);

    $this->artisan('campaigns:dispatch-due')->assertSuccessful();

    expect(cmpsendCampaignRow($due)->status)->toBe('sending')
        ->and(cmpsendCampaignRow($future)->status)->toBe('scheduled')
        ->and(cmpsendCampaignRow($broken)->status)->toBe('draft')
        ->and(cmpsendCampaignRow($broken)->scheduled_at)->toBeNull();

    Queue::assertPushed(StartCampaignSendJob::class, 1);
    Queue::assertPushed(StartCampaignSendJob::class, fn ($job) => $job->campaignId === $due);
});

// ---------------------------------------------------------------- public unsubscribe

it('shows a confirmation page on GET without unsubscribing anyone', function () {
    $tenant = cmpsendTenant();
    $id = cmpsendCampaign($tenant->id);
    $recipientId = cmpsendRecipient($tenant->id, $id, 'ada@example.com');
    $token = UnsubscribeToken::make($tenant->id, $recipientId);

    $this->get('/unsubscribe/'.$token)
        ->assertOk()
        ->assertSee('a***@example.com', false)
        ->assertDontSee('ada@example.com', false);

    expect(EmailSuppressions::isSuppressed($tenant->id, 'ada@example.com'))->toBeFalse();
});

it('unsubscribes on POST, also for one-click requests, and is idempotent', function () {
    $tenant = cmpsendTenant();
    $id = cmpsendCampaign($tenant->id);
    $recipientId = cmpsendRecipient($tenant->id, $id, 'Ada@Example.com');
    $token = UnsubscribeToken::make($tenant->id, $recipientId);

    $this->post('/unsubscribe/'.$token, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    $this->post('/unsubscribe/'.$token)->assertOk();

    expect(EmailSuppressions::isSuppressed($tenant->id, 'ada@example.com'))->toBeTrue()
        ->and(DB::table('email_suppressions')->where('tenant_id', $tenant->id)->count())->toBe(1);

    $row = DB::table('email_suppressions')->where('tenant_id', $tenant->id)->first();
    expect($row->reason)->toBe('unsubscribed')
        ->and((int) $row->campaign_id)->toBe($id);
});

it('answers every bad token with the same 404 and suppresses nothing', function () {
    $tenant = cmpsendTenant();
    $other = Tenant::factory()->create();
    $id = cmpsendCampaign($tenant->id);
    $recipientId = cmpsendRecipient($tenant->id, $id, 'ada@example.com');

    $forged = UnsubscribeToken::make($other->id, $recipientId);
    $good = UnsubscribeToken::make($tenant->id, $recipientId);
    $tampered = substr($good, 0, -1).(substr($good, -1) === 'A' ? 'B' : 'A');
    $unknown = UnsubscribeToken::make($tenant->id, 999999);

    foreach ([$forged, $tampered, $unknown, 'not-a-real-token-at-all'] as $token) {
        $this->get('/unsubscribe/'.$token)->assertNotFound();
        $this->post('/unsubscribe/'.$token)->assertNotFound();
    }

    expect(DB::table('email_suppressions')->count())->toBe(0);
});