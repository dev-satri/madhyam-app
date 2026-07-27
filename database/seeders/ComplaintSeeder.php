<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test complaints — one open (high priority) + one resolved
 * so both branches of the complaints UI have data.
 */
class ComplaintSeeder extends Seeder
{
    public function run(): void
    {
        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        $c2 = DB::table('clients')->where('name', 'Trek Nepal Adventures')->value('id');
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->value('id');

        $rows = [
            [
                'client_id' => $c1,
                'title' => 'Reel delivered 2 days late',
                'description' => 'The festival reel was promised on Friday but arrived on Sunday. Please look into the process gap.',
                'status' => 'open',
                'assigned_to' => $admin,
                'priority' => 'high',
                'resolution_notes' => null,
                'status_notes' => null,
            ],
            [
                'client_id' => $c2,
                'title' => 'Caption tone too formal',
                'description' => 'The autumn campaign caption felt corporate — please match the adventure tone from the brand guide.',
                'status' => 'resolved',
                'assigned_to' => $admin,
                'priority' => 'medium',
                'resolution_notes' => 'Reviewed brand guide with copywriter; updated tone for future captions.',
                'status_notes' => 'Client acknowledged fix on the next post.',
            ],
        ];

        foreach ($rows as $r) {
            DB::table('complaints')->insert(array_merge($r, [
                'created_at' => now()->subDays(4),
                'updated_at' => now(),
            ]));
        }

        $this->command?->info('  ✓ Complaints: 2 (1 open high-priority, 1 resolved)');
    }
}
