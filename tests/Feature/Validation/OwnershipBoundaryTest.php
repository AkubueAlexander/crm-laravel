<?php

use App\Domain\Accounts\Actions\CreateAccountAction;
use App\Domain\Accounts\Actions\UpdateAccountAction;
use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\Actions\UpdateContactAction;
use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function owngdSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function owngdActor(Tenant $tenant): User
{
    owngdSwitch($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $names = ['accounts.create', 'accounts.update', 'contacts.create', 'contacts.update'];
    foreach ($names as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $user->givePermissionTo($names);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('owngd account actions refuse a foreign owner and ignore stray keys', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorB = owngdActor($b);
    $actorA = owngdActor($a);

    $create = app(CreateAccountAction::class);
    $update = app(UpdateAccountAction::class);

    expect(fn () => $create->execute($actorA, ['name' => 'Sneaky', 'owner_id' => $actorB->id]))->toThrow(ValidationException::class);

    $account = $create->execute($actorA, ['name' => 'Mine', 'owner_id' => $actorA->id, 'tenant_id' => $b->id]);
    expect((int) $account->tenant_id)->toBe($a->id);

    expect(fn () => $update->execute($actorA, $account, ['owner_id' => $actorB->id]))->toThrow(ValidationException::class);
    expect((int) $account->fresh()->owner_id)->toBe($actorA->id);
});

it('owngd contact actions refuse a foreign or deleted account and ignore stray keys', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    owngdSwitch($b);
    $foreign = Account::factory()->create();

    $actorA = owngdActor($a);
    $mine = Account::factory()->create();
    $gone = Account::factory()->create();
    $gone->delete();

    $create = app(CreateContactAction::class);
    $update = app(UpdateContactAction::class);

    expect(fn () => $create->execute($actorA, ['last_name' => 'X', 'account_id' => $foreign->id]))->toThrow(ValidationException::class);
    expect(fn () => $create->execute($actorA, ['last_name' => 'X', 'account_id' => $gone->id]))->toThrow(ValidationException::class);

    $contact = $create->execute($actorA, ['last_name' => 'Ok', 'account_id' => $mine->id, 'tenant_id' => $b->id]);
    expect((int) $contact->tenant_id)->toBe($a->id);

    expect(fn () => $update->execute($actorA, $contact, ['account_id' => $foreign->id]))->toThrow(ValidationException::class);
    expect((int) $contact->fresh()->account_id)->toBe($mine->id);
});