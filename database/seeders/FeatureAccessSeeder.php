<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeatureAccessSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            'dashboard', 'clients', 'packages', 'contentPlanner', 'workflow', 'tasks', 'approvals',
            'files', 'reports', 'leaves', 'expenses', 'salary', 'overtime',
            'team', 'settings', 'userGuide', 'complaints', 'clientPortal',
        ];

        $roles = [
            'super-admin' => array_fill_keys($features, true),
            'admin' => array_fill_keys($features, true),
            'manager' => array_fill_keys([
                'dashboard', 'clients', 'packages', 'contentPlanner', 'workflow', 'tasks', 'approvals',
                'files', 'reports', 'leaves', 'expenses', 'salary', 'overtime',
                'team', 'userGuide', 'complaints', 'clientPortal',
            ], true),
            'editor' => array_fill_keys(['dashboard', 'tasks', 'approvals', 'files'], true),
            'videographer' => array_fill_keys(['dashboard', 'tasks', 'workflow', 'files'], true),
            'designer' => array_fill_keys(['dashboard', 'tasks', 'files'], true),
            'copywriter' => array_fill_keys(['dashboard', 'tasks', 'files'], true),
            'social-media' => array_fill_keys(['dashboard', 'contentPlanner', 'tasks', 'files'], true),
        ];

        foreach ($roles as $role => $feats) {
            DB::table('feature_access')->insert([
                'role' => $role,
                'features' => json_encode($feats),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
