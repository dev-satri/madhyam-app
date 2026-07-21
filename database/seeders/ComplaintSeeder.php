<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ComplaintSeeder extends Seeder
{
    public function run(): void
    {
        $clients = DB::table('clients')->pluck('id', 'name');
        $users = DB::table('users')->pluck('id', 'email');

        $rows = [
            [
                'client_id' => $clients['Himalayan Coffee'] ?? null,
                'title' => 'Late delivery of reel',
                'description' => 'The festival reel was delivered 2 days late',
                'status' => 'open',
                'assigned_to' => $users['rajesh@madhyam.com'] ?? null,
                'priority' => 'high',
            ],
            [
                'client_id' => $clients['Kathmandu Bites'] ?? null,
                'title' => 'Caption tone mismatch',
                'description' => 'The caption tone was too formal for our brand',
                'status' => 'resolved',
                'assigned_to' => $users['karma@madhyam.com'] ?? null,
                'priority' => 'medium',
            ],
        ];

        foreach ($rows as $r) {
            DB::table('complaints')->insert(array_merge($r, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
