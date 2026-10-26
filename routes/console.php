<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 8a.4: flag overdue activities server-side; the client never polls for this.
Schedule::command('activities:flag-overdue')->everyFiveMinutes()->withoutOverlapping();

// 8b.4: delete expired contact export files.
Schedule::command('contacts:prune-exports')->hourly()->withoutOverlapping();

// 9.4c: start scheduled campaigns whose send time has arrived.
Schedule::command('campaigns:dispatch-due')->everyMinute()->withoutOverlapping();

// 9.6c-2: run the next step of every due automation enrollment.
Schedule::command('automations:run-due')->everyMinute()->withoutOverlapping();

// 9.6c-2: enroll contacts that now match the segment of an active "segment_entered" automation.
Schedule::command('automations:scan-segments')->everyFiveMinutes()->withoutOverlapping();
