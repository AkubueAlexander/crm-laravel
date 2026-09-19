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

        Config::set('permission.teams', true);
        Config::set('permission.column_names.team_foreign_key', 'tenant_id');

        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {

        $this->app->resolving(PermissionRegistrar::class, function (PermissionRegistrar $registrar) {
            if (TenantContext::hasTenant()) {
                $registrar->setPermissionsTeamId(TenantContext::id());
            }
        });


        JsonResource::withoutWrapping();
    }
}
