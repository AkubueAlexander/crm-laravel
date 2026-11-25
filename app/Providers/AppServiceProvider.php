<?php

namespace App\Providers;

use App\Models\Activity;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {

        if (file_exists(base_path('routes/channels.php'))) {
            require base_path('routes/channels.php');
        }

        JsonResource::withoutWrapping();

        Activity::registerMorphMap();
    }
}
