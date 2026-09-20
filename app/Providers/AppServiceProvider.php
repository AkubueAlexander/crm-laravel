<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

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

        if (file_exists(base_path('routes/channels.php'))) {
            require base_path('routes/channels.php');
        }

        JsonResource::withoutWrapping();
    }
}
