<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('activities:flag-overdue')->everyFiveMinutes()->withoutOverlapping();


Schedule::command('contacts:prune-exports')->hourly()->withoutOverlapping();


Schedule::command('campaigns:dispatch-due')->everyMinute()->withoutOverlapping();


Schedule::command('automations:run-due')->everyMinute()->withoutOverlapping();


Schedule::command('automations:scan-segments')->everyFiveMinutes()->withoutOverlapping();


Schedule::command('leads:rescore')->hourly()->withoutOverlapping();
