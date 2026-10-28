<?php

namespace Database\Seeders;

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;


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
        'leads.view',
        'leads.create',
        'leads.update',
        'leads.delete',
        'leads.convert',
        'activities.view',
        'activities.create',
        'activities.update',
        'activities.complete',
        'activities.delete',
        'settings.manage',
        'users.manage',
        'audit.view',
        'marketing.view',
        'marketing.manage',
        'products.view',
        'products.manage',
        'quotes.view',
        'quotes.manage',
        'quotes.approve',
    ];

    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'acme'],
            ['name' => 'Acme'],
        );

        TenantContext::set($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);


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

        // 8c.2: the standard role set (admin, manager, sales, viewer) for this tenant.
        RoleCatalog::provision($tenant);

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
