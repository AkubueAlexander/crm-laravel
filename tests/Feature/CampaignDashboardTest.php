<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

function cmpdashUser(Tenant $tenant, bool $allow = true): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($allow) {
        Gate::define('marketing.view', fn () => true);
    }

    return $user;
}

function cmpdashCampaign(Tenant $tenant, string $name = 'Spring Sale'): int
{
    return DB::table('campaigns')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cmpdashRecipient(Tenant $tenant, int $campaignId, string $email, array $over = []): void
{
    DB::table('campaign_recipients')->insert(array_merge([
        'tenant_id' => $tenant->id,
        'campaign_id' => $campaignId,
        'email' => $email,
        'email_normalized' => $email,
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ], $over));
}

it('cmpdash reports totals, rates and the daily series from recipient rows', function () {
    $tenant = Tenant::factory()->create();
    $user = cmpdashUser($tenant);
    $id = cmpdashCampaign($tenant);

    cmpdashRecipient($tenant, $id, 'a@x.test', ['sent_at' => '2026-10-20 10:00:00', 'opened_at' => '2026-10-20 11:00:00', 'clicked_at' => '2026-10-21 09:00:00']);
    cmpdashRecipient($tenant, $id, 'b@x.test', ['sent_at' => '2026-10-20 10:00:00', 'opened_at' => '2026-10-21 08:00:00']);
    cmpdashRecipient($tenant, $id, 'c@x.test', ['sent_at' => '2026-10-20 10:00:00', 'bounced_at' => '2026-10-20 12:00:00']);
    cmpdashRecipient($tenant, $id, 'd@x.test', ['status' => 'failed']);
    DB::table('email_suppressions')->insert([
        'tenant_id' => $tenant->id,
        'email_normalized' => 'a@x.test',
        'reason' => 'unsubscribed',
        'campaign_id' => $id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $json = $this->actingAs($user)->getJson("/api/v1/campaigns/{$id}/report")->assertOk()->json();

    expect($json['totals'])->toEqual([
        'recipients' => 4, 'sent' => 3, 'failed' => 1, 'skipped' => 0, 'pending' => 0,
        'delivered' => 2, 'opened' => 2, 'clicked' => 1, 'bounced' => 1, 'complained' => 0, 'unsubscribed' => 1,
    ]);
    expect($json['rates'])->toEqual(['open' => 100.0, 'click' => 50.0, 'bounce' => 33.3, 'unsubscribe' => 50.0]);
    expect($json['series'])->toEqual([
        ['date' => '2026-10-20', 'sent' => 3, 'opened' => 1, 'clicked' => 0, 'bounced' => 1],
        ['date' => '2026-10-21', 'sent' => 0, 'opened' => 1, 'clicked' => 1, 'bounced' => 0],
    ]);
    expect($json['campaign']['name'])->toBe('Spring Sale');
});

it('cmpdash has null rates and an empty series when nothing was sent', function () {
    $tenant = Tenant::factory()->create();
    $user = cmpdashUser($tenant);
    $id = cmpdashCampaign($tenant);

    $json = $this->actingAs($user)->getJson("/api/v1/campaigns/{$id}/report")->assertOk()->json();

    expect($json['rates'])->toEqual(['open' => null, 'click' => null, 'bounce' => null, 'unsubscribe' => null]);
    expect($json['series'])->toEqual([]);
});

it('cmpdash attributes only this tenants leads by utm_campaign', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = cmpdashUser($tenant);
    $id = cmpdashCampaign($tenant, 'Spring Sale');

    TenantContext::set($tenant);
    $mine = Lead::factory()->create();
    $unrelated = Lead::factory()->create();
    TenantContext::set($other);
    $theirs = Lead::factory()->create();

    DB::table('leads')->where('id', $mine->id)->update(['utm_campaign' => 'spring sale']);
    DB::table('leads')->where('id', $unrelated->id)->update(['utm_campaign' => 'other']);
    DB::table('leads')->where('id', $theirs->id)->update(['utm_campaign' => 'Spring Sale']);

    $json = $this->actingAs($user)->getJson("/api/v1/campaigns/{$id}/report")->assertOk()->json();

    expect($json['attribution']['leads'])->toBe(1);
    expect($json['attribution']['deals'])->toBe(0);
});

it('cmpdash returns 404 for another tenants campaign', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = cmpdashUser($tenant);
    $theirs = cmpdashCampaign($other);

    $this->actingAs($user)->getJson("/api/v1/campaigns/{$theirs}/report")->assertNotFound();
});

it('cmpdash is forbidden without marketing.view', function () {
    $tenant = Tenant::factory()->create();
    $user = cmpdashUser($tenant, false);
    $id = cmpdashCampaign($tenant);

    $this->actingAs($user)->getJson("/api/v1/campaigns/{$id}/report")->assertForbidden();
});
