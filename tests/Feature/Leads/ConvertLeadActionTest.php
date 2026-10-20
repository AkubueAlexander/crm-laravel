<?php

use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\Exceptions\LeadAlreadyConvertedException;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;

test('converting a lead creates a contact and a deal and marks the lead converted', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create();
    $lead = Lead::factory()->for($tenant)->create(['status' => 'new']);

    $result = app(ConvertLeadAction::class)->execute($lead, $user);

    expect($result['contact'])->not->toBeNull()
        ->and($result['deal'])->not->toBeNull();

    $lead->refresh();

    expect($lead->status)->toBe('converted')
        ->and($lead->converted_at)->not->toBeNull()
        ->and($lead->contact_id)->toBe($result['contact']->id)
        ->and($lead->deal_id)->toBe($result['deal']->id);
});

test('converting an already-converted lead throws', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create();
    $lead = Lead::factory()->for($tenant)->create([
        'status' => 'converted',
        'converted_at' => now(),
    ]);

    app(ConvertLeadAction::class)->execute($lead, $user);
})->throws(LeadAlreadyConvertedException::class);