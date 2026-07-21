<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DataAccessSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'seeAllTasks', 'seeAllWorkflow', 'seeAllPerformance', 'seeAllActivity',
            'canAddTasks', 'canMoveWorkflow', 'canEditWorkflow',
        ];

        $roles = [
            'super-admin' => array_fill_keys($permissions, true),
            'admin' => array_fill_keys($permissions, true),
            'manager' => array_fill_keys([
                'seeAllTasks', 'seeAllWorkflow', 'seeAllActivity',
                'canAddTasks', 'canMoveWorkflow', 'canEditWorkflow',
            ], true),
            'editor' => array_fill_keys(['canMoveWorkflow'], true),
            'videographer' => array_fill_keys(['canMoveWorkflow'], true),
            'designer' => array_fill_keys(['canMoveWorkflow'], true),
            'copywriter' => array_fill_keys(['canMoveWorkflow'], true),
            'social-media' => array_fill_keys(['canMoveWorkflow'], true),
        ];

        foreach ($roles as $role => $perms) {
            DB::table('data_access')->insert([
                'role' => $role,
                'permissions' => json_encode($perms),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
