<?php

namespace App\Domain\Users;

use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 8c.2: the fixed set of roles every tenant gets, and the permissions each one carries.
 * Roles are tenant-scoped rows (Spatie teams mode); their definitions live here, in code,
 * so they are reviewable and identical across tenants. There is no role editor.
 */
final class RoleCatalog
{
    public const ADMIN = 'admin';

    /** Every permission the application checks today. DevTenantSeeder::PERMISSIONS must match (tested). */
    public const ALL_PERMISSIONS = [
        'contacts.view',
        'contacts.create',
        'contacts.update',
        'contacts.delete',
        'deals.view',
        'deals.transition',
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
        'invoices.view',
        'invoices.manage',
        'inventory.view',
        'inventory.manage',
        'accounting.view',
        'accounting.manage',
        'hr.view',
        'hr.manage',
    ];

    /** Held by administrators only. */
    private const MANAGEMENT = ['settings.manage', 'users.manage', 'audit.view'];

    /** Order matters: it is the display order and the tie-break for a user holding several roles. */
    public const ROLES = [
        'admin' => [
            'label' => 'Administrator',
            'description' => 'Full access, including users and workspace settings.',
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Full access to CRM data and the forecast. No user or settings management.',
        ],
        'sales' => [
            'label' => 'Sales',
            'description' => 'Create and edit contacts, accounts, leads and activities, and convert leads.',
        ],
        'viewer' => [
            'label' => 'Viewer',
            'description' => 'Read-only access.',
        ],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ROLES);
    }

    public static function isKnown(string $role): bool
    {
        return array_key_exists($role, self::ROLES);
    }

    public static function label(string $role): string
    {
        return self::ROLES[$role]['label'] ?? ucfirst($role);
    }

    /** @return list<array{name: string, label: string, description: string}> */
    public static function describe(): array
    {
        $out = [];

        foreach (self::ROLES as $name => $definition) {
            $out[] = ['name' => $name, 'label' => $definition['label'], 'description' => $definition['description']];
        }

        return $out;
    }

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        return match ($role) {
            'admin' => self::ALL_PERMISSIONS,
            'manager' => array_values(array_diff(self::ALL_PERMISSIONS, self::MANAGEMENT)),
            'sales' => [
                'contacts.view', 'contacts.create', 'contacts.update',
                'accounts.view', 'accounts.create', 'accounts.update',
                'leads.view', 'leads.create', 'leads.update', 'leads.convert',
                'activities.view', 'activities.create', 'activities.update', 'activities.complete',
                'marketing.view', // sales: read-only marketing
                'products.view', // sales: read-only catalog
                'quotes.view', 'quotes.manage', // sales: build their own quotes
                'invoices.view', // sales: see invoices
                'inventory.view', // sales: see stock
                'hr.view', // sales: see teams and territories
                'deals.view', 'deals.transition',
            ],
            'viewer' => array_values(array_filter(self::ALL_PERMISSIONS, fn (string $p) => str_ends_with($p, '.view') && ! in_array($p, self::MANAGEMENT, true))),
            default => [],
        };
    }

    /**
     * The role to show for a user holding these role names: the first catalog role present,
     * else whatever they hold (a legacy role outside the catalog), else null.
     *
     * @param list<string> $names
     */
    public static function primary(array $names): ?string
    {
        foreach (self::names() as $role) {
            if (in_array($role, $names, true)) {
                return $role;
            }
        }

        return $names[0] ?? null;
    }

    /** Creates the permissions and this tenant's roles, and re-syncs each role to the catalog. Idempotent. */
    public static function provision(Tenant $tenant): void
    {
        self::ensurePermissions();

        foreach (self::ROLES as $name => $_) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
            $role->syncPermissions(self::permissionsFor($name));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Creates any missing role of a tenant (with its permissions) without touching existing ones. */
    public static function ensureRoles(int $tenantId): void
    {
        self::ensurePermissions();

        foreach (self::ROLES as $name => $_) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'tenant_id' => $tenantId]);

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions(self::permissionsFor($name));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Active administrators of a tenant. Uses the current permissions team (set from the actor's tenant). */
    public static function activeAdminCount(int $tenantId): int
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deactivated_at')
            ->whereHas('roles', fn ($roles) => $roles->where('roles.name', self::ADMIN))
            ->count();
    }

    private static function ensurePermissions(): void
    {
        foreach (self::ALL_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }
}
