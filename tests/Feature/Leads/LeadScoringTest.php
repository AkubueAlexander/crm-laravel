<?php

use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\Scoring\LeadScoring;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function lscoreActor(string $role = 'admin'): User
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** email 10 + company 10 + last name 5 + event 15 = 40 */
function lscoreNewLead(User $actor, array $override = []): Lead
{
    return app(CreateLeadAction::class)->execute($actor, array_merge([
        'first_name' => 'Lena',
        'last_name' => 'Lead',
        'email' => 'lena@example.test',
        'company' => 'Lead Co',
        'source' => 'event',
    ], $override));
}

function lscoreEngagement(User $actor, string $email, array $times): void
{
    $campaign = DB::table('campaigns')->insertGetId([
        'tenant_id' => $actor->tenant_id, 'name' => 'C '.uniqid('', true), 'subject' => 'Hi', 'status' => 'sent',
        'created_by' => $actor->id, 'lock_version' => 0, 'recipients_count' => 0, 'sent_count' => 0, 'failed_count' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('campaign_recipients')->insert($times + [
        'tenant_id' => $actor->tenant_id, 'campaign_id' => $campaign, 'contact_id' => null,
        'email' => $email, 'email_normalized' => mb_strtolower($email), 'status' => 'sent', 'attempts' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('lscore: the rules add up, cap at 100 and floor at 0', function () {
    $full = LeadScoring::compute(
        ['email' => 'a@example.test', 'phone' => '555', 'company' => 'X', 'last_name' => 'Y', 'source' => 'referral', 'status' => 'qualified'],
        ['opened' => true, 'clicked' => true],
    );
    expect($full['score'])->toBe(100);
    expect(array_column($full['factors'], 'key'))->toBe(['email', 'phone', 'company', 'last_name', 'source', 'status', 'opened', 'clicked']);

    $bare = LeadScoring::compute(['status' => 'new']);
    expect($bare)->toEqual(['score' => 0, 'factors' => []]);

    $web = LeadScoring::compute(['email' => 'a@example.test', 'phone' => '555', 'source' => 'web', 'status' => 'new']);
    expect($web['score'])->toBe(25);

    $unknownSource = LeadScoring::compute(['source' => 'podcast', 'status' => 'new']);
    expect($unknownSource['score'])->toBe(5);

    $bounced = LeadScoring::compute(['email' => 'a@example.test', 'status' => 'new'], ['bad' => true]);
    expect($bounced['score'])->toBe(0);

    $notAnEmail = LeadScoring::compute(['email' => 'nope', 'status' => 'new']);
    expect($notAnEmail['score'])->toBe(0);

    $out = LeadScoring::compute(['email' => 'a@example.test', 'company' => 'X', 'status' => 'disqualified']);
    expect($out['score'])->toBe(0);
    expect(array_column($out['factors'], 'key'))->toBe(['disqualified']);
});

it('lscore: creating a lead stores the score and returns it on the model', function () {
    $actor = lscoreActor();

    $lead = lscoreNewLead($actor);

    expect($lead->lead_score)->toBe(40);
    expect(array_column($lead->score_factors, 'key'))->toBe(['email', 'company', 'last_name', 'source']);
    expect((int) DB::table('leads')->where('id', $lead->id)->value('lead_score'))->toBe(40);
    expect(DB::table('leads')->where('id', $lead->id)->value('score_updated_at'))->not->toBeNull();
});

it('lscore: updating and converting a lead rescores it', function () {
    $actor = lscoreActor();
    $lead = lscoreNewLead($actor);

    $updated = app(UpdateLeadAction::class)->execute($actor, $lead, ['status' => 'qualified']);
    expect($updated->lead_score)->toBe(65);

    $updated = app(UpdateLeadAction::class)->execute($actor, $lead, ['status' => 'disqualified']);
    expect($updated->lead_score)->toBe(0);
    expect((int) DB::table('leads')->where('id', $lead->id)->value('lead_score'))->toBe(0);

    $other = lscoreNewLead($actor, ['email' => 'second@example.test']);
    $converted = app(ConvertLeadAction::class)->execute($actor, $other);
    expect($converted->status)->toBe('converted');
    expect((int) DB::table('leads')->where('id', $other->id)->value('lead_score'))->toBe(65);
});

it('lscore: leads:rescore picks up recent engagement of the same tenant only', function () {
    $actor = lscoreActor();
    $tenant = Tenant::query()->find($actor->tenant_id);
    $lead = lscoreNewLead($actor);
    expect((int) DB::table('leads')->where('id', $lead->id)->value('lead_score'))->toBe(40);

    // The same address opening mail in ANOTHER tenant must not count.
    $other = lscoreActor();
    lscoreEngagement($other, 'lena@example.test', ['opened_at' => now(), 'clicked_at' => now()]);
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);

    $this->artisan('leads:rescore')->assertSuccessful();
    expect((int) DB::table('leads')->where('id', $lead->id)->value('lead_score'))->toBe(40);

    // Engagement in this tenant: +10 opened, +15 clicked.
    lscoreEngagement($actor, 'LENA@example.test', ['opened_at' => now(), 'clicked_at' => now()]);
    $this->artisan('leads:rescore')->assertSuccessful();
    expect((int) DB::table('leads')->where('id', $lead->id)->value('lead_score'))->toBe(65);

    // Old engagement does not trigger a rescore by itself ...
    DB::table('leads')->where('id', $lead->id)->update(['lead_score' => 1]);
    DB::table('campaign_recipients')->where('tenant_id', $actor->tenant_id)->update(['opened_at' => now()->subDays(3), 'clicked_at' => now()->subDays(3)]);
    $this->artisan('leads:rescore')->assertSuccessful();
    expect((int) DB::table('leads')->where('id', $lead->id)->value('lead_score'))->toBe(1);

    // ... but --all does.
    $this->artisan('leads:rescore', ['--all' => true])->assertSuccessful();
    expect((int) DB::table('leads')->where('id', $lead->id)->value('lead_score'))->toBe(65);
});

it('lscore: the lead API exposes the score and its factors read-only', function () {
    $actor = lscoreActor();
    $lead = lscoreNewLead($actor);
    Sanctum::actingAs($actor);

    $res = $this->getJson("/api/v1/leads/{$lead->id}")->assertOk();

    expect($res->json('score'))->toBe(40);
    expect($res->json('score_updated_at'))->not->toBeNull();
    expect(array_column($res->json('score_factors'), 'points'))->toBe([10, 10, 5, 15]);
    expect(array_keys($res->json('score_factors.0')))->toEqualCanonicalizing(['key', 'label', 'points']);

    // The score is never writable through the API.
    $this->putJson("/api/v1/leads/{$lead->id}", ['first_name' => 'Lena', 'lead_score' => 99])->assertOk();
    expect($lead->fresh()->lead_score)->toBe(40);
});