<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rebuild the daily aggregates for today and yesterday every hour (docs/spec.md 9). Idempotent.
Schedule::command('ekko:aggregate')->hourly()->withoutOverlapping();
