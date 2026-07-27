<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Final-test client-portal accounts (auth:client guard).
 *
 * One portal account per client. Emails are the ones the client themselves
 * log in with — the client company's separate contact email lives on the
 * clients row.
 */
class ClientAccountSeeder extends Seeder
{
    public function run(): void
    {
        $clients = DB::table('clients')->pluck('id', 'name');

        $rows = [
            [
                'client' => 'Himalayan Coffee Co.',
                'email' => 'client1@madhyam.com',
                'name' => 'Ram Sharma',
                'password' => 'Client@123',
            ],
            [
                'client' => 'Trek Nepal Adventures',
                'email' => 'client2@madhyam.com',
                'name' => 'Maya Gurung',
                'password' => 'Client@123',
            ],
        ];

        foreach ($rows as $r) {
            $clientId = $clients[$r['client']] ?? null;
            if (! $clientId) {
                continue;
            }

            DB::table('client_accounts')->updateOrInsert(
                ['email' => $r['email']],
                [
                    'client_id' => $clientId,
                    'password' => Hash::make($r['password']),
                    'name' => $r['name'],
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $this->command?->info('  ✓ Client portal accounts: 2 (client1@madhyam.com, client2@madhyam.com)');
    }
}
