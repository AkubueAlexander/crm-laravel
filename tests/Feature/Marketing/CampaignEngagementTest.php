<?php

use App\Domain\Marketing\Engagement\EngagementToken;
use App\Domain\Marketing\Engagement\EngagementTracking;
use App\Domain\Marketing\Sending\UnsubscribeToken;
use App\Domain\Tenant\TenantContext;
use App\Jobs\SendCampaignChunkJob;
use App\Mail\CampaignEmail;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** @return array{0: Tenant, 1: int} a tenant and a sent campaign with a template */
function cmpengSetup(string $status = 'sent'): array
{
    Queue::fake();
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);

    return [$tenant, cmpengCampaign($tenant->id, $status)];
}

function cmpengCampaign(int $tenantId, string $status = 'sent'): int
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

    return DB::table('campaigns')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Camp '.Str::random(8),
        'status' => $status,
        'lock_version' => 1,
        'segment_id' => $segmentId,
        'email_template_id' => $templateId,
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cmpengRecipient(int $tenantId, int $campaignId, string $email = 'ada@example.com', string $status = 'sent', ?string $name = null): int
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

function cmpengRecipientRow(int $id): object
{
    return DB::table('campaign_recipients')->where('id', $id)->first();
}

function cmpengCampaignRow(int $id): object
{
    return DB::table('campaigns')->where('id', $id)->first();
}

/** Flip the FIRST character: the last base64 character carries unused bits, so changing it can leave the bytes unchanged. */
function cmpengTamper(string $token): string
{
    return ($token[0] === 'A' ? 'B' : 'A').substr($token, 1);
}

function cmpengWebhook($case, string $body, ?string $signature)
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($signature !== null) {
        $server['HTTP_X_SIGNATURE'] = $signature;
    }

    return $case->call('POST', '/webhooks/email-events', [], [], [], $server, $body);
}

function cmpengSignedWebhook($case, array $payload)
{
    $body = json_encode($payload);

    return cmpengWebhook($case, $body, hash_hmac('sha256', $body, 'test-secret'));
}

// ---------------------------------------------------------------- tokens

it('keeps open, click and unsubscribe tokens apart and rejects tampering', function () {
    $open = EngagementToken::makeOpen(1, 2);
    $click = EngagementToken::makeClick(1, 2, 'https://example.org/x');

    expect(EngagementToken::parseOpen($open))->toBe(['tenant_id' => 1, 'recipient_id' => 2])
        ->and(EngagementToken::parseClick($click))->toBe(['tenant_id' => 1, 'recipient_id' => 2, 'url' => 'https://example.org/x'])
        ->and(EngagementToken::parseClick($open))->toBeNull()
        ->and(EngagementToken::parseOpen($click))->toBeNull()
        ->and(EngagementToken::parseOpen(UnsubscribeToken::make(1, 2)))->toBeNull()
        ->and(UnsubscribeToken::parse($open))->toBeNull()
        ->and(EngagementToken::parseOpen(cmpengTamper($open)))->toBeNull()
        ->and(EngagementToken::parseOpen('garbage'))->toBeNull();

    expect(fn () => EngagementToken::makeClick(1, 2, 'javascript:alert(1)'))->toThrow(InvalidArgumentException::class);
    expect(fn () => EngagementToken::makeClick(1, 2, 'https://example.org/'.str_repeat('a', EngagementToken::MAX_URL)))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------- open pixel

it('records an open once per recipient and always answers with the pixel', function () {
    [$tenant, $campaign] = cmpengSetup();
    $ada = cmpengRecipient($tenant->id, $campaign, 'ada@example.com');
    $bob = cmpengRecipient($tenant->id, $campaign, 'bob@example.com');
    $token = EngagementToken::makeOpen($tenant->id, $ada);

    $this->get('/e/o/'.$token)->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->get('/e/o/'.$token)->assertOk();

    expect(cmpengRecipientRow($ada)->opened_at)->not->toBeNull()
        ->and(cmpengRecipientRow($bob)->opened_at)->toBeNull()
        ->and((int) cmpengCampaignRow($campaign)->opened_count)->toBe(1);

    $this->get('/e/o/'.EngagementToken::makeOpen($tenant->id, $bob))->assertOk();
    expect((int) cmpengCampaignRow($campaign)->opened_count)->toBe(2);
});

it('answers a bad open token with the same pixel and changes nothing', function () {
    [$tenant, $campaign] = cmpengSetup();
    $ada = cmpengRecipient($tenant->id, $campaign);
    $click = EngagementToken::makeClick($tenant->id, $ada, 'https://example.org/x');
    $good = EngagementToken::makeOpen($tenant->id, $ada);
    $tampered = cmpengTamper($good);

    foreach ([$click, $tampered, 'not-a-token', EngagementToken::makeOpen($tenant->id, 999999)] as $token) {
        $this->get('/e/o/'.$token)->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    expect(cmpengRecipientRow($ada)->opened_at)->toBeNull()
        ->and((int) cmpengCampaignRow($campaign)->opened_count)->toBe(0);
});

it('never records engagement for a recipient of another tenant', function () {
    [$tenantA, $campaignA] = cmpengSetup();
    [$tenantB, $campaignB] = cmpengSetup();
    $bobInB = cmpengRecipient($tenantB->id, $campaignB, 'bob@example.com');

    // A token signed for tenant A pointing at tenant B's recipient id.
    $this->get('/e/o/'.EngagementToken::makeOpen($tenantA->id, $bobInB))->assertOk();
    $this->get('/e/c/'.EngagementToken::makeClick($tenantA->id, $bobInB, 'https://example.org/x'))->assertRedirect('https://example.org/x');

    expect(cmpengRecipientRow($bobInB)->opened_at)->toBeNull()
        ->and(cmpengRecipientRow($bobInB)->clicked_at)->toBeNull()
        ->and((int) cmpengCampaignRow($campaignB)->opened_count)->toBe(0)
        ->and((int) cmpengCampaignRow($campaignA)->opened_count)->toBe(0);
});

// ---------------------------------------------------------------- click redirect

it('redirects to the signed url and records the click, which also counts as an open', function () {
    [$tenant, $campaign] = cmpengSetup();
    $ada = cmpengRecipient($tenant->id, $campaign);
    $url = 'https://example.org/page?a=1&b=2';
    $token = EngagementToken::makeClick($tenant->id, $ada, $url);

    $this->get('/e/c/'.$token)->assertRedirect($url);
    $this->get('/e/c/'.$token)->assertRedirect($url);

    $row = cmpengRecipientRow($ada);
    expect($row->clicked_at)->not->toBeNull()
        ->and($row->opened_at)->not->toBeNull()
        ->and((int) cmpengCampaignRow($campaign)->clicked_count)->toBe(1)
        ->and((int) cmpengCampaignRow($campaign)->opened_count)->toBe(1);
});

it('answers a bad click token with a 404 and never redirects', function () {
    [$tenant, $campaign] = cmpengSetup();
    $ada = cmpengRecipient($tenant->id, $campaign);
    $good = EngagementToken::makeClick($tenant->id, $ada, 'https://example.org/x');
    $tampered = cmpengTamper($good);

    foreach ([$tampered, EngagementToken::makeOpen($tenant->id, $ada), UnsubscribeToken::make($tenant->id, $ada), 'not-a-token'] as $token) {
        $this->get('/e/c/'.$token)->assertNotFound();
    }

    expect(cmpengRecipientRow($ada)->clicked_at)->toBeNull();
});

// ---------------------------------------------------------------- html rewriting

it('rewrites tracked links, leaves the rest alone and adds the pixel before the body ends', function () {
    $html = '<html><body><p><a href="https://example.org/a?x=1&amp;y=2" class="b">One</a> '
        .'<a href="mailto:me@example.org">Mail</a> '
        .'<a href=\'https://example.org/single\'>Two</a> '
        .'<a href="https://app.test/unsubscribe/abc.def">Unsub</a></p></body></html>';

    $out = EngagementTracking::apply($html, 5, 9);

    expect(substr_count($out, '/e/c/'))->toBe(2)
        ->and($out)->toContain('mailto:me@example.org')
        ->and($out)->toContain('https://app.test/unsubscribe/abc.def')
        ->and($out)->not->toContain('https://example.org/single')
        ->and(strpos($out, '/e/o/'))->toBeLessThan(strpos($out, '</body>'));

    preg_match('#/e/c/([A-Za-z0-9_.-]+)#', $out, $m);
    expect(EngagementToken::parseClick($m[1]))->toBe(['tenant_id' => 5, 'recipient_id' => 9, 'url' => 'https://example.org/a?x=1&y=2']);
});

// ---------------------------------------------------------------- send integration

it('adds the pixel and the recipient header to a real send, and nothing when tracking is off', function () {
    [$tenant, $campaign] = cmpengSetup('sending');
    Mail::fake();
    $ada = cmpengRecipient($tenant->id, $campaign, 'ada@example.com', 'pending', 'Ada Lovelace');

    app()->call([new SendCampaignChunkJob($tenant->id, $campaign), 'handle']);

    Mail::assertSent(CampaignEmail::class, fn (CampaignEmail $m) => str_contains($m->htmlBody, '/e/o/')
        && ($m->extraHeaders['X-Campaign-Recipient'] ?? null) === UnsubscribeToken::make($tenant->id, $ada)
        && str_contains($m->htmlBody, '/unsubscribe/'));

    config(['services.campaigns.tracking' => false]);
    [$tenant2, $campaign2] = cmpengSetup('sending');
    Mail::fake();
    cmpengRecipient($tenant2->id, $campaign2, 'bob@example.com', 'pending', 'Bob Stone');

    app()->call([new SendCampaignChunkJob($tenant2->id, $campaign2), 'handle']);

    Mail::assertSent(CampaignEmail::class, fn (CampaignEmail $m) => ! str_contains($m->htmlBody, '/e/o/'));
});

// ---------------------------------------------------------------- webhook

it('is off without a secret and rejects a missing or wrong signature', function () {
    [$tenant, $campaign] = cmpengSetup();
    $ada = cmpengRecipient($tenant->id, $campaign);
    $body = json_encode(['events' => [['type' => 'bounce', 'recipient' => UnsubscribeToken::make($tenant->id, $ada)]]]);

    config(['services.email_events.secret' => null]);
    cmpengWebhook($this, $body, hash_hmac('sha256', $body, 'test-secret'))->assertNotFound();

    config(['services.email_events.secret' => 'test-secret']);
    cmpengWebhook($this, $body, null)->assertStatus(401);
    cmpengWebhook($this, $body, hash_hmac('sha256', $body, 'wrong-secret'))->assertStatus(401);
    cmpengWebhook($this, $body, hash_hmac('sha256', $body.' ', 'test-secret'))->assertStatus(401);

    expect(cmpengRecipientRow($ada)->bounced_at)->toBeNull()
        ->and(DB::table('email_suppressions')->count())->toBe(0);
});

it('records a hard bounce once, suppresses the address and is idempotent on replay', function () {
    config(['services.email_events.secret' => 'test-secret']);
    [$tenant, $campaign] = cmpengSetup();
    $ada = cmpengRecipient($tenant->id, $campaign, 'Ada@Example.com');
    $payload = ['events' => [['type' => 'bounce', 'recipient' => UnsubscribeToken::make($tenant->id, $ada)]]];

    cmpengSignedWebhook($this, $payload)->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('ignored', 0);
    cmpengSignedWebhook($this, $payload)->assertOk()->assertJsonPath('processed', 1);

    expect(cmpengRecipientRow($ada)->bounced_at)->not->toBeNull()
        ->and((int) cmpengCampaignRow($campaign)->bounced_count)->toBe(1)
        ->and(DB::table('email_suppressions')->where('tenant_id', $tenant->id)->count())->toBe(1);

    $row = DB::table('email_suppressions')->where('tenant_id', $tenant->id)->first();
    expect($row->email_normalized)->toBe('ada@example.com')
        ->and($row->reason)->toBe('bounced')
        ->and((int) $row->campaign_id)->toBe($campaign);
});

it('counts a soft bounce without suppressing the address', function () {
    config(['services.email_events.secret' => 'test-secret']);
    [$tenant, $campaign] = cmpengSetup();
    $ada = cmpengRecipient($tenant->id, $campaign);

    cmpengSignedWebhook($this, ['events' => [['type' => 'bounce', 'permanent' => false, 'recipient' => UnsubscribeToken::make($tenant->id, $ada)]]])
        ->assertOk()->assertJsonPath('processed', 1);

    expect(cmpengRecipientRow($ada)->bounced_at)->not->toBeNull()
        ->and((int) cmpengCampaignRow($campaign)->bounced_count)->toBe(1)
        ->and(DB::table('email_suppressions')->count())->toBe(0);
});

it('suppresses on a complaint without touching bounced_count', function () {
    config(['services.email_events.secret' => 'test-secret']);
    [$tenant, $campaign] = cmpengSetup();
    $ada = cmpengRecipient($tenant->id, $campaign);

    cmpengSignedWebhook($this, ['events' => [['type' => 'complaint', 'recipient' => UnsubscribeToken::make($tenant->id, $ada)]]])
        ->assertOk()->assertJsonPath('processed', 1);

    expect(cmpengRecipientRow($ada)->complained_at)->not->toBeNull()
        ->and((int) cmpengCampaignRow($campaign)->bounced_count)->toBe(0)
        ->and(DB::table('email_suppressions')->value('reason'))->toBe('complaint');
});

it('ignores unknown, tampered, cross-tenant and unsupported events', function () {
    config(['services.email_events.secret' => 'test-secret']);
    [$tenantA] = cmpengSetup();
    [$tenantB, $campaignB] = cmpengSetup();
    $bobInB = cmpengRecipient($tenantB->id, $campaignB, 'bob@example.com');
    $good = UnsubscribeToken::make($tenantB->id, $bobInB);

    $response = cmpengSignedWebhook($this, ['events' => [
        ['type' => 'bounce', 'recipient' => UnsubscribeToken::make($tenantA->id, 999999)],
        ['type' => 'bounce', 'recipient' => UnsubscribeToken::make($tenantA->id, $bobInB)],
        ['type' => 'bounce', 'recipient' => cmpengTamper($good)],
        ['type' => 'delivered', 'recipient' => $good],
        ['type' => 'bounce'],
        'nonsense',
    ]]);

    $response->assertOk()->assertJsonPath('processed', 0)->assertJsonPath('ignored', 6);
    expect(cmpengRecipientRow($bobInB)->bounced_at)->toBeNull()
        ->and(DB::table('email_suppressions')->count())->toBe(0);
});

it('rejects a malformed or oversized events list', function () {
    config(['services.email_events.secret' => 'test-secret']);

    cmpengSignedWebhook($this, [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    cmpengSignedWebhook($this, ['events' => 'nope'])->assertStatus(422);
    cmpengSignedWebhook($this, ['events' => ['a' => 1]])->assertStatus(422);
    cmpengSignedWebhook($this, ['events' => array_fill(0, 101, ['type' => 'bounce'])])->assertStatus(422);
});