<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'slug' => 'basic', 'name' => 'Basic', 'monthly_amount' => 15000,
                'deliverables' => '4 posts per month',
                'features' => json_encode(['4 posts per month', 'Basic analytics', 'Email support']),
                'content_limit' => 8, 'workflow_limit' => 5, 'storage_limit_mb' => 512,
                'revision_limit' => 2, 'priority_support' => false,
                'included_platforms' => json_encode(['instagram', 'facebook']),
                'status' => 'active',
            ],
            [
                'slug' => 'standard', 'name' => 'Standard', 'monthly_amount' => 30000,
                'deliverables' => '8 reels + 4 posts per month',
                'features' => json_encode(['8 reels + 4 posts per month', 'Story content', 'Analytics dashboard', 'Priority support']),
                'content_limit' => 20, 'workflow_limit' => 12, 'storage_limit_mb' => 2048,
                'revision_limit' => 4, 'priority_support' => true,
                'included_platforms' => json_encode(['instagram', 'facebook', 'tiktok', 'twitter']),
                'status' => 'active',
            ],
            [
                'slug' => 'premium', 'name' => 'Premium', 'monthly_amount' => 45000,
                'deliverables' => '12 reels + 8 posts + 4 stories',
                'features' => json_encode(['12 reels + 8 posts + 4 stories', 'Video editing', 'Full analytics', 'Dedicated manager']),
                'content_limit' => 35, 'workflow_limit' => 25, 'storage_limit_mb' => 5120,
                'revision_limit' => 6, 'priority_support' => true,
                'included_platforms' => json_encode(['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'linkedin']),
                'status' => 'active',
            ],
            [
                'slug' => 'enterprise', 'name' => 'Enterprise', 'monthly_amount' => 75000,
                'deliverables' => '16 reels + 12 posts + 8 stories + 2 videos',
                'features' => json_encode(['16 reels + 12 posts + 8 stories + 2 videos', 'Full production', 'Strategy meetings', '24/7 support', 'Custom reporting']),
                'content_limit' => 60, 'workflow_limit' => 40, 'storage_limit_mb' => 10240,
                'revision_limit' => 999, 'priority_support' => true,
                'included_platforms' => json_encode(['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'linkedin']),
                'status' => 'active',
            ],
        ];
        foreach ($rows as $r) {
            DB::table('packages')->updateOrInsert(
                ['slug' => $r['slug']],
                array_merge($r, ['updated_at' => now(), 'created_at' => now()])
            );
        }
    }
}
