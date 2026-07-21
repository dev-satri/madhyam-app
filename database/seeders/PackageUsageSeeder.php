<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PackageUsageSeeder extends Seeder
{
    public function run(): void
    {
        $clients = DB::table('clients')->select('id', 'package')->get();
        $packageMap = DB::table('packages')->pluck('id', 'slug')->toArray();

        $now = now();

        foreach ($clients as $client) {
            $packageId = $packageMap[$client->package] ?? null;

            DB::table('package_usage')->updateOrInsert(
                ['client_id' => $client->id, 'month' => $now->month, 'year' => $now->year],
                [
                    'package_id' => $packageId,
                    'content_created' => rand(3, 12),
                    'content_published' => rand(2, 10),
                    'workflow_items' => rand(1, 8),
                    'approvals_used' => rand(1, 5),
                    'files_uploaded' => rand(2, 15),
                    'storage_used_bytes' => rand(50000000, 500000000),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $prevMonth = $now->copy()->subMonth();
            DB::table('package_usage')->updateOrInsert(
                ['client_id' => $client->id, 'month' => $prevMonth->month, 'year' => $prevMonth->year],
                [
                    'package_id' => $packageId,
                    'content_created' => rand(4, 15),
                    'content_published' => rand(3, 12),
                    'workflow_items' => rand(2, 10),
                    'approvals_used' => rand(2, 6),
                    'files_uploaded' => rand(3, 18),
                    'storage_used_bytes' => rand(60000000, 600000000),
                    'created_at' => $prevMonth,
                    'updated_at' => $prevMonth,
                ]
            );
        }
    }
}
