<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendBackupReminderCommand extends Command
{
    protected $signature = 'backup:remind';

    protected $description = 'Set the backup reminder flag on settings for dashboard banner display';

    public function handle(): int
    {
        $settings = DB::table('settings')->where('id', 1)->first();

        if (! $settings) {
            $this->error('Settings row not found.');

            return Command::FAILURE;
        }

        $lastReminder = $settings->last_backup_reminder ?? null;
        $intervalDays = $settings->backup_reminder_days ?? 7;

        if ($lastReminder && now()->diffInDays(Carbon::parse($lastReminder)) < $intervalDays) {
            $this->info('Backup reminder already sent within the interval. Skipping.');

            return Command::SUCCESS;
        }

        DB::table('settings')->where('id', 1)->update([
            'last_backup_reminder' => now(),
            'updated_at' => now(),
        ]);

        $this->info('Backup reminder flag updated.');

        return Command::SUCCESS;
    }
}
