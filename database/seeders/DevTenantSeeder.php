<?php

namespace Database\Seeders;

use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent dev-environment seeder: one tenant, the real permission set this
 * app actually enforces today, an admin role scoped under Spatie teams mode
 * (tenant_id is the team_foreign_key), and one login-ready admin user.
 *
 * Safe to re-run after any RefreshDatabase wipe:
 * php artisan db:seed --class=Database\Seeders\DevTenantSeeder
 */
class DevTenantSeeder extends Seeder
{
    public const PERMISSIONS = [
        'contacts.view',
        'contacts.create',
        'contacts.update',
        'contacts.delete',
        'deals.view',
        'forecast.view',
        'accounts.view',
        'accounts.create',
        'accounts.update',
        'accounts.delete',
    ];

    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'acme'],
            ['name' => 'Acme'],
        );

        TenantContext::set($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        // Permissions are global (no tenant_id column on this table by design --
        // Spatie's teams pattern scopes ROLES and their assignments per team,
        // while the permission catalog itself is shared across all teams).
        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]),
        );

        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        $adminRole->syncPermissions($permissions);

        $user = User::updateOrCreate(
            ['email' => 'admin@acme.test'],
            [
                'name' => 'Admin',
                'tenant_id' => $tenant->id,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        if (! $user->hasRole('admin')) {
            $user->assignRole($adminRole);
        }

        TenantContext::clear();

        $this->command?->info("Dev tenant ready: {$tenant->name} (id {$tenant->id})");
        $this->command?->info("Login: admin@acme.test / password");
    }
}