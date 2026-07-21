<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['role_key' => 'junior-editor',  'name' => 'Junior Editor',  'description' => 'Entry-level editor with limited access'],
            ['role_key' => 'senior-designer', 'name' => 'Senior Designer', 'description' => 'Lead designer with expanded file and workflow access'],
        ];

        foreach ($roles as $r) {
            DB::table('custom_roles')->updateOrInsert(
                ['role_key' => $r['role_key']],
                [
                    'name' => $r['name'],
                    'description' => $r['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
