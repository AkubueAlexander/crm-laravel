<?php

use App\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Domain\Marketing\Actions\DeleteEmailTemplateAction;
use App\Domain\Marketing\Sending\EmailSuppressions;
use App\Domain\Marketing\Sending\RecipientSnapshot;
use App\Domain\Marketing\Sending\SendRenderer;
use App\Domain\Marketing\Sending\UnsubscribeToken;
use App\Domain\Marketing\Templates\TemplateRenderer;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function cmprcpTenant(): Tenant
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return $tenant;
}

function cmprcpManager(Tenant $tenant): User
{
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('manager');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function cmprcpContact(Tenant $tenant, ?string $email, string $first = 'Ada', string $last = 'Lovelace', ?string $job = null): Contact
{
    TenantContext::set($tenant);

    return Contact::factory()->create([
        'tenant_id' => $tenant->id,
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'job_title' => $job,
    ]);
}

function cmprcpSegment(int $tenantId): int
{
    // Empty conditions = every contact of the tenant.
    return DB::table('segments')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Seg '.Str::random(8),
        'match_mode' => 'all',
        'conditions' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cmprcpTemplate(int $tenantId): int
{
    return DB::table('email_templates')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Tpl '.Str::random(8),
        'subject' => 'Hello {{ first_name }}',
        'body' => 'Hi {{ first_name }}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cmprcpCampaign(int $tenantId, array $overrides = []): int
{
    return DB::table('campaigns')->insertGetId(array_merge([
        'tenant_id' => $tenantId,
        'name' => 'Camp '.Str::random(8),
        'status' => 'draft',
        'lock_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function cmprcpRows(int $campaignId)
{
    return DB::table('campaign_recipients')->where('campaign_id', $campaignId)->orderBy('email_normalized')->get();
}

it('snapshots the segment audience of this tenant only, with a copy of name, email and job title', function () {
    $tenant = cmprcpTenant();
    $other = Tenant::factory()->create();
    cmprcpContact($tenant, 'ada@example.com', 'Ada', 'Lovelace', 'Engineer');
    cmprcpContact($tenant, 'bob@example.com', 'Bob', 'Stone');
    cmprcpContact($other, 'carol@other.test', 'Carol', 'Other');
    TenantContext::set($tenant);

    $campaignId = cmprcpCampaign($tenant->id, ['segment_id' => cmprcpSegment($tenant->id)]);

    $result = RecipientSnapshot::take($tenant->id, $campaignId);

    expect($result)->toMatchArray(['already_taken' => false, 'total' => 2, 'skipped_suppressed' => 0, 'skipped_invalid' => 0]);

    $rows = cmprcpRows($campaignId);
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('email_normalized')->all())->toBe(['ada@example.com', 'bob@example.com'])
        ->and($rows->pluck('tenant_id')->unique()->all())->toBe([$tenant->id])
        ->and($rows->pluck('status')->unique()->all())->toBe(['pending']);

    $ada = $rows->firstWhere('email_normalized', 'ada@example.com');
    expect($ada->name)->toBe('Ada Lovelace')
        ->and($ada->job_title)->toBe('Engineer')
        ->and($ada->email)->toBe('ada@example.com');
});

it('leaves out suppressed addresses, invalid addresses and deleted contacts, and reports the counts', function () {
    $tenant = cmprcpTenant();
    cmprcpContact($tenant, 'ok@example.com');
    cmprcpContact($tenant, 'Blocked@Example.com');
    cmprcpContact($tenant, 'not-an-email');
    cmprcpContact($tenant, 'gone@example.com')->delete();
    TenantContext::set($tenant);
    EmailSuppressions::suppress($tenant->id, 'blocked@example.com', 'unsubscribed');

    $campaignId = cmprcpCampaign($tenant->id, ['segment_id' => cmprcpSegment($tenant->id)]);

    $result = RecipientSnapshot::take($tenant->id, $campaignId);

    expect($result)->toMatchArray(['total' => 1, 'skipped_suppressed' => 1, 'skipped_invalid' => 1])
        ->and(cmprcpRows($campaignId)->pluck('email_normalized')->all())->toBe(['ok@example.com']);
});

it('keeps the audience frozen: a second take changes nothing even if contacts were added', function () {
    $tenant = cmprcpTenant();
    cmprcpContact($tenant, 'one@example.com');
    cmprcpContact($tenant, 'two@example.com');
    TenantContext::set($tenant);
    $campaignId = cmprcpCampaign($tenant->id, ['segment_id' => cmprcpSegment($tenant->id)]);

    RecipientSnapshot::take($tenant->id, $campaignId);
    cmprcpContact($tenant, 'late@example.com');
    TenantContext::set($tenant);

    $again = RecipientSnapshot::take($tenant->id, $campaignId);

    expect($again)->toMatchArray(['already_taken' => true, 'total' => 2])
        ->and(cmprcpRows($campaignId))->toHaveCount(2);
});

it('refuses a campaign without a segment and never reads another tenant\'s campaign', function () {
    $tenant = cmprcpTenant();
    $other = Tenant::factory()->create();
    $noSegment = cmprcpCampaign($tenant->id);
    $foreign = cmprcpCampaign($other->id, ['segment_id' => cmprcpSegment($other->id)]);
    TenantContext::set($tenant);

    expect(fn () => RecipientSnapshot::take($tenant->id, $noSegment))->toThrow(DomainException::class);
    expect(fn () => RecipientSnapshot::take($tenant->id, $foreign))->toThrow(ModelNotFoundException::class);

    expect(DB::table('campaign_recipients')->count())->toBe(0);
});

it('mails an address once even when two contacts share it with different casing', function () {
    $tenant = cmprcpTenant();
    try {
        cmprcpContact($tenant, 'Dup@Example.com', 'First', 'One');
        cmprcpContact($tenant, 'dup@example.com', 'Second', 'Two');
    } catch (Throwable) {
        test()->markTestSkipped('The contacts table does not allow two contacts with the same address.');
    }
    TenantContext::set($tenant);
    $campaignId = cmprcpCampaign($tenant->id, ['segment_id' => cmprcpSegment($tenant->id)]);

    $result = RecipientSnapshot::take($tenant->id, $campaignId);

    expect($result['total'])->toBe(1)
        ->and(cmprcpRows($campaignId))->toHaveCount(1);
});

it('suppresses an address once, normalizes it, keeps the first reason and scopes it to the tenant', function () {
    $tenant = cmprcpTenant();
    $other = Tenant::factory()->create();

    expect(EmailSuppressions::suppress($tenant->id, '  Mixed@Example.COM ', 'unsubscribed'))->toBeTrue()
        ->and(EmailSuppressions::suppress($tenant->id, 'mixed@example.com', 'bounced'))->toBeFalse()
        ->and(EmailSuppressions::isSuppressed($tenant->id, 'MIXED@example.com'))->toBeTrue()
        ->and(EmailSuppressions::isSuppressed($other->id, 'mixed@example.com'))->toBeFalse();

    expect(DB::table('email_suppressions')->where('tenant_id', $tenant->id)->value('reason'))->toBe('unsubscribed');
    expect(fn () => EmailSuppressions::suppress($tenant->id, 'x@example.com', 'because'))->toThrow(InvalidArgumentException::class);
});

it('signs unsubscribe tokens and rejects anything tampered with', function () {
    $token = UnsubscribeToken::make(7, 4242);

    expect(UnsubscribeToken::parse($token))->toBe(['tenant_id' => 7, 'recipient_id' => 4242]);

    [$payload, $signature] = explode('.', $token);
    $forgedPayload = rtrim(strtr(base64_encode('8.4242'), '+/', '-_'), '=');
    $flipped = substr($signature, 0, -1).($signature[strlen($signature) - 1] === 'A' ? 'B' : 'A');

    expect(UnsubscribeToken::parse($forgedPayload.'.'.$signature))->toBeNull()
        ->and(UnsubscribeToken::parse($payload.'.'.$flipped))->toBeNull()
        ->and(UnsubscribeToken::parse('garbage'))->toBeNull()
        ->and(UnsubscribeToken::parse(''))->toBeNull()
        ->and(UnsubscribeToken::parse($payload))->toBeNull();

    expect(UnsubscribeToken::url($token))->toEndWith('/unsubscribe/'.$token);
});

it('adds the footer, the unsubscribe link and the List-Unsubscribe headers at send time', function () {
    $url = 'https://app.example.test/unsubscribe/abc.def';

    $mail = SendRenderer::render(
        'Hello {{ first_name }}',
        "Hi {{ first_name }},\n\nSee [docs](https://example.com/docs).",
        ['first_name' => 'Ada', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'job_title' => ''],
        $url,
        'Acme <Ltd>',
        '1 Main St, Lagos',
    );

    expect($mail['subject'])->toBe('Hello Ada')
        ->and($mail['html'])->toContain('Hi Ada')
        ->and($mail['html'])->toContain('href="'.$url.'"')
        ->and($mail['html'])->toContain('Acme &lt;Ltd&gt;')
        ->and($mail['html'])->toContain('1 Main St, Lagos')
        ->and($mail['html'])->toEndWith('</div></body></html>')
        ->and(strpos($mail['html'], 'Unsubscribe'))->toBeLessThan(strpos($mail['html'], '</body>'))
        ->and($mail['text'])->toContain('Unsubscribe: '.$url)
        ->and($mail['text'])->toContain('docs (https://example.com/docs)')
        ->and($mail['headers']['List-Unsubscribe'])->toBe('<'.$url.'>')
        ->and($mail['headers']['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
});

it('refuses an unsafe unsubscribe URL', function (string $bad) {
    expect(fn () => SendRenderer::render('S', 'B', [], $bad, 'Acme'))->toThrow(InvalidArgumentException::class);
})->with(['javascript:alert(1)', "https://x.test/a\r\nBcc: evil@example.com", 'https://x.test/<b>', '/relative']);

it('leaves the editor preview untouched: TemplateRenderer still adds no footer', function () {
    $out = TemplateRenderer::render('S', 'Body', []);

    expect($out['html'])->not->toContain('Unsubscribe')
        ->and($out['text'])->not->toContain('Unsubscribe');
});

it('refuses to delete a template used by a scheduled campaign', function () {
    $tenant = cmprcpTenant();
    $user = cmprcpManager($tenant);
    $templateId = cmprcpTemplate($tenant->id);
    cmprcpCampaign($tenant->id, ['status' => 'scheduled', 'email_template_id' => $templateId, 'scheduled_at' => now()->addDay()]);

    expect(fn () => app(DeleteEmailTemplateAction::class)($user, $templateId))->toThrow(ValidationException::class);

    expect(DB::table('email_templates')->where('id', $templateId)->exists())->toBeTrue();
});

it('deletes a template used only by a draft and leaves that draft without a template', function () {
    $tenant = cmprcpTenant();
    $user = cmprcpManager($tenant);
    $templateId = cmprcpTemplate($tenant->id);
    $draftId = cmprcpCampaign($tenant->id, ['email_template_id' => $templateId]);

    app(DeleteEmailTemplateAction::class)($user, $templateId);

    expect(DB::table('email_templates')->where('id', $templateId)->exists())->toBeFalse()
        ->and(DB::table('campaigns')->where('id', $draftId)->value('email_template_id'))->toBeNull();
});