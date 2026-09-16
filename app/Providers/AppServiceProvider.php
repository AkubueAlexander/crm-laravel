<?php

namespace App\Providers;

use App\Domain\Tenant\TenantContext;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 2.0: spatie/laravel-permission teams-mode, scoped by tenant_id.
        // Pairs with config/permission.php's 'teams' => true and
        // 'team_foreign_key' => 'tenant_id'.
        Config::set('permission.teams', true);
        Config::set('permission.column_names.team_foreign_key', 'tenant_id');

        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        // Keep Spatie's permission-registrar team id in sync with the request's
        // resolved TenantContext (set by ResolveTenant middleware) rather than
        // trusting any client-supplied value.
        $this->app->resolving(PermissionRegistrar::class, function (PermissionRegistrar $registrar) {
            if (TenantContext::hasTenant()) {
                $registrar->setPermissionsTeamId(TenantContext::id());
            }
        });

        // 7.0: every API Resource in this app returns a flat shape, not Laravel's
        // default { "data": {...} } envelope — keeps every Zod schema on the
        // frontend a 1:1 match with the Resource's toArray() without an unwrap
        // step in every single TanStack Query hook.
        JsonResource::withoutWrapping();
    }
}
