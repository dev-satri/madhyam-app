<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test package usage.
 *
 * Current month + previous month rows for both clients so the Packages
 * page has trend data. Client 2 (Trek Nepal — near-expiry, Standard
 * package) also gets a high storage usage (~93% of the Standard 2 GB
 * limit) so the quota warning UI has something to render.
 */
class PackageUsageSeeder extends Seeder
{
    public function run(): void
    {
        $clients = DB::table('clients')->select('id', 'name', 'package')->get();
        $packageIds = DB::table('packages')->pluck('id', 'slug')->toArray();

        $now = now();
        $prev = $now->copy()->subMonth();

        foreach ($clients as $client) {
            $packageId = $packageIds[$client->package] ?? null;

            // Trigger warning for Trek Nepal — high storage usage.
            // Trek Nepal is on the Standard package (2 GB / 2048 MB storage cap).
            // 1.9 GB ≈ 93 % of that cap → lands in the amber "warning" band without
            // going over-quota, so the near-quota UI has something realistic to show.
            $isTrekNepal = str_contains($client->name, 'Trek Nepal');
            $storageBytesCurrent = $isTrekNepal
                ? 1_930_000_000     // ~1.9 GB of the Standard 2 GB limit — warning zone
                : 850_000_000;      // healthy

            DB::table('package_usage')->updateOrInsert(
                ['client_id' => $client->id, 'month' => $now->month, 'year' => $now->year],
                [
                    'package_id' => $packageId,
                    'content_created' => $isTrekNepal ? 7 : 9,
                    'content_published' => $isTrekNepal ? 3 : 5,
                    'workflow_items' => 6,
                    'approvals_used' => 3,
                    'files_uploaded' => 5,
                    'storage_used_bytes' => $storageBytesCurrent,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            DB::table('package_usage')->updateOrInsert(
                ['client_id' => $client->id, 'month' => $prev->month, 'year' => $prev->year],
                [
                    'package_id' => $packageId,
                    'content_created' => 11,
                    'content_published' => 8,
                    'workflow_items' => 8,
                    'approvals_used' => 5,
                    'files_uploaded' => 10,
                    'storage_used_bytes' => 1_800_000_000,
                    'created_at' => $prev,
                    'updated_at' => $prev,
                ]
            );
        }

        $this->command?->info('  ✓ Package usage: current + previous month (Trek Nepal set to warning zone)');
    }
}
