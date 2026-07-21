<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FileExpirySeeder extends Seeder
{
    public function run(): void
    {
        $retentionDays = (int) (DB::table('settings')->value('file_retention_days') ?? 5);

        $files = DB::table('files')->select('id')->get();

        foreach ($files as $file) {
            $exists = DB::table('file_expiries')->where('file_id', $file->id)->exists();
            if ($exists) {
                continue;
            }

            DB::table('file_expiries')->insert([
                'file_id' => $file->id,
                'expiry_date' => now()->addDays($retentionDays)->toDateString(),
                'extended' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
