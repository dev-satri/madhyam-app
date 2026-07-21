<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ClientAccountSeeder extends Seeder
{
    public function run(): void
    {
        $clients = DB::table('clients')->pluck('id', 'name');

        $rows = [
            ['client' => 'Himalayan Coffee',        'email' => 'ram@himalayancoffee.com', 'name' => 'Ram Bahadur',   'password' => 'client123'],
            ['client' => 'Nepal Trek Adventures',   'email' => 'maya@treknepal.com',      'name' => 'Maya Tamang',   'password' => 'client123'],
            ['client' => 'GreenLeaf Organic',       'email' => 'devi@greenleaf.com',      'name' => 'Devi Shrestha', 'password' => 'client123'],
        ];

        foreach ($rows as $r) {
            DB::table('client_accounts')->insert([
                'client_id' => $clients[$r['client']],
                'email' => $r['email'],
                'password' => Hash::make($r['password']),
                'name' => $r['name'],
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
