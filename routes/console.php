<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit rows are written on every create/update/delete and are never updated, so the
// table only grows. Spatie's cleaner drops anything older than activitylog.clean_after_days:
// ten years, not the framework's 365, because the trail has to outlive the orders and payouts
// it explains. See docs/audit-trail.md.
Schedule::command('activitylog:clean')->daily()->at('01:30');

// Where an IP is: DB-IP City Lite comes out early each month (docs/visitor-origin.md). Its free
// licence expects the current file, and an old one places newly reassigned addresses wrongly.
Schedule::command('location:update')->monthlyOn(3, '02:00')->withoutOverlapping();
