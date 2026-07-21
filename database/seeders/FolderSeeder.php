<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FolderSeeder extends Seeder
{
    public function run(): void
    {
        $himalayanId = DB::table('clients')->where('name', 'Himalayan Coffee')->value('id');

        $rows = [
            ['name' => 'Himalayan Coffee', 'client_id' => $himalayanId, 'parent_id' => null],
            ['name' => 'Exports',          'client_id' => null,         'parent_id' => null],
            ['name' => 'Raw Footage',      'client_id' => null,         'parent_id' => null],
            ['name' => 'Templates',        'client_id' => null,         'parent_id' => null],
        ];

        foreach ($rows as $r) {
            DB::table('folders')->insert(array_merge($r, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}
