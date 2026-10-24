<?php
use App\Models\Tenant;
use App\Models\User;
use App\Domain\Users\RoleCatalog;
use Illuminate\Support\Facades\Hash;

$tenant = Tenant::findOrFail(27);
RoleCatalog::ensureRoles($tenant->id);
setPermissionsTeamId($tenant->id);

foreach ([
    ['Maya Manager', 'maya@acme.test', 'manager'],
    ['Sam Sales', 'sam@acme.test', 'sales'],
    ['Vera Viewer', 'vera@acme.test', 'viewer'],
] as [$name, $email, $role]) {
    $u = User::where('tenant_id', $tenant->id)->where('email', $email)->first() ?? new User();
    $u->forceFill([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'email' => $email,
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ])->save();
    $u->syncRoles([$role]);
}
echo "seeded\n";
