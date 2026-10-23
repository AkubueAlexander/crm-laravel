<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 8a.4: flag overdue activities server-side; the client never polls for this.
Schedule::command('activities:flag-overdue')->everyFiveMinutes()->withoutOverlapping();
