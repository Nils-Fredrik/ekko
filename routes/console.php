<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rebuild the daily aggregates for today and the days back every hour (docs/spec.md 9). Idempotent.
Schedule::command('ekko:aggregate')->hourly()->withoutOverlapping();

// Evaluate the alerts and deliver the daily digest (docs/spec.md 11.1). A quarter past the hour, after the
// aggregation. Each tenant is evaluated once a day, after ekko.alerts.evaluate_after in its own timezone.
Schedule::command('ekko:evaluate-alerts')->hourlyAt(15)->withoutOverlapping();
