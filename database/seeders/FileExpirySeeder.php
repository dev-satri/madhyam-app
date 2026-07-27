<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test file expiries.
 *
 * Every file gets an expiry row. The first two files are near-expiry
 * (in 2 days) so the "expiring soon" filter has data to show.
 */
class FileExpirySeeder extends Seeder
{
    public function run(): void
    {
        $retentionDays = (int) (DB::table('settings')->value('file_retention_days') ?? 5);

        $files = DB::table('files')->orderBy('id')->get();

        foreach ($files as $i => $file) {
            $expiryDate = $i < 2
                ? now()->addDays(2)->toDateString()          // near expiry — test warning UI
                : now()->addDays($retentionDays)->toDateString();

            DB::table('file_expiries')->updateOrInsert(
                ['file_id' => $file->id],
                [
                    'expiry_date' => $expiryDate,
                    'extended' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command?->info('  ✓ File expiries: all files (first 2 near-expiry for warning UI)');
    }
}
