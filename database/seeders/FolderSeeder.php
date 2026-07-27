<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test folders — one shared "Templates" folder + one per client.
 */
class FolderSeeder extends Seeder
{
    public function run(): void
    {
        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        $c2 = DB::table('clients')->where('name', 'Trek Nepal Adventures')->value('id');

        $rows = [
            ['name' => 'Templates',              'client_id' => null, 'parent_id' => null],
            ['name' => 'Himalayan Coffee',       'client_id' => $c1,  'parent_id' => null],
            ['name' => 'Trek Nepal Adventures',  'client_id' => $c2,  'parent_id' => null],
        ];

        foreach ($rows as $r) {
            DB::table('folders')->insert(array_merge($r, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command?->info('  ✓ Folders: 3 (Templates shared + 1 per client)');
    }
}
