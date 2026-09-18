<?php

use App\Console\Commands\AutoGenerateBenefitPeriods;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily housekeeping — see AutoGenerateBenefitPeriods for the rule. Runs at
// 1:00 AM (low-traffic hour) so it doesn't overlap with normal daytime use.
// NOTE: this only registers the schedule — something still needs to trigger
// `php artisan schedule:run` regularly for it to actually fire. On this
// offline Windows/Laragon setup, see the Windows Task Scheduler instructions
// provided alongside this command, since there's no cron here by default.
Schedule::command(AutoGenerateBenefitPeriods::class)->dailyAt('01:00');
