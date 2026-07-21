<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Creative',    'description' => 'Design and creative team'],
            ['name' => 'Production',  'description' => 'Video and content production'],
            ['name' => 'Marketing',   'description' => 'Social media and marketing'],
            ['name' => 'Management',  'description' => 'Administration and management'],
        ];
        foreach ($rows as $r) {
            DB::table('departments')->insert(array_merge($r, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}
