<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('logs in via the real /login endpoint and stays authenticated on a subsequent request, surviving the fail-closed User tenant scope', function () {
    $tenant = Tenant::factory()->createOne();

    $user = User::factory()->createOne([
        'tenant_id' => $tenant->id,
        'password' => Hash::make('correct-password'),
    ]);

    $login = $this->withHeaders(['Referer' => 'http://localhost:5173'])
        ->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

    $login->assertOk();

    $me = $this->withHeaders(['Referer' => 'http://localhost:5173'])
        ->getJson('/api/v1/me');

    $me->assertOk();
    $me->assertJsonPath('id', $user->id);
});

it('rejects a login with a wrong password without ever exposing a tenant-scope error', function () {
    $tenant = Tenant::factory()->createOne();

    $user = User::factory()->createOne([
        'tenant_id' => $tenant->id,
        'password' => Hash::make('correct-password'),
    ]);

    $this->withHeaders(['Referer' => 'http://localhost:5173'])
        ->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
});