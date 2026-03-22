<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:budget-alerts')
    ->dailyAt('09:00')
    ->timezone('Asia/Jakarta');

Schedule::command('notifications:dss-weekly')
    ->mondays()
    ->at('09:00')
    ->timezone('Asia/Jakarta');
