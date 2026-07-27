<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Payment reminders: send due/overdue notifications daily at 9:00 AM
Schedule::command('notifications:payment-reminders')->dailyAt('09:00');

// Contract expiry notifications: daily at 10:00 AM
Schedule::command('notifications:contract-expiry')->dailyAt('10:00');

// Package usage check: daily at 11:00 AM
Schedule::command('notifications:package-usage')->dailyAt('11:00');

// Content daily reminder: daily at 08:00 AM
Schedule::command('notifications:content-daily')->dailyAt('08:00');

// Deadline reminders: daily at 09:30 AM
Schedule::command('notifications:deadline-reminders')->dailyAt('09:30');

// Expire files: daily at midnight
Schedule::command('files:expire')->dailyAt('00:00');

// Backup reminder: weekly on Sunday at 06:00 AM
Schedule::command('backup:reminder')->weeklyOn(0, '06:00');

// Shoot reminder: daily at 07:00 AM
Schedule::command('shoot:reminder')->dailyAt('07:00');

// Client expiry followup: daily at 10:30 AM
Schedule::command('client:expiry-followup')->dailyAt('10:30');
