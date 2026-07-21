<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'name' => 'Himalayan Coffee', 'contact' => 'Ram Bahadur', 'email' => 'ram@himalayancoffee.com', 'phone' => '+977-9841000001',
                'package' => 'premium', 'amount' => 45000,
                'contract_start' => '2026-01-01', 'contract_end' => '2026-12-31', 'status' => 'active',
                'deliverables' => '12 reels, 8 posts, 4 stories per month',
                'brand_guide' => 'Warm tones, premium feel, earthy colors, serif fonts',
                'social_links' => "instagram.com/himalayancoffee\nfacebook.com/himalayancoffee",
                'notes' => 'VIP client, priority handling',
            ],
            [
                'name' => 'Nepal Trek Adventures', 'contact' => 'Maya Tamang', 'email' => 'maya@treknepal.com', 'phone' => '+977-9841000002',
                'package' => 'standard', 'amount' => 30000,
                'contract_start' => '2026-03-01', 'contract_end' => '2026-12-31', 'status' => 'active',
                'deliverables' => '8 reels, 4 posts, 2 stories per month',
                'brand_guide' => 'Adventure, mountains, green & blue, bold fonts',
                'social_links' => "facebook.com/treknepal\ninstagram.com/treknepal",
                'notes' => 'Seasonal campaigns important',
            ],
            [
                'name' => 'Kathmandu Bites', 'contact' => 'Suman Maharjan', 'email' => 'suman@ktmbites.com', 'phone' => '+977-9841000003',
                'package' => 'basic', 'amount' => 18000,
                'contract_start' => '2026-02-15', 'contract_end' => '2026-08-15', 'status' => 'active',
                'deliverables' => '4 reels, 4 posts per month',
                'brand_guide' => 'Fun, colorful, foodie vibes, casual tone',
                'social_links' => 'instagram.com/ktmbites',
                'notes' => null,
            ],
            [
                'name' => 'GreenLeaf Organic', 'contact' => 'Devi Shrestha', 'email' => 'devi@greenleaf.com', 'phone' => '+977-9841000004',
                'package' => 'enterprise', 'amount' => 75000,
                'contract_start' => '2026-01-01', 'contract_end' => '2027-01-01', 'status' => 'active',
                'deliverables' => '16 reels, 12 posts, 8 stories, 2 videos per month',
                'brand_guide' => 'Organic, green, health-focused, minimal, clean',
                'social_links' => "instagram.com/greenleaforganic\nyoutube.com/greenleaf",
                'notes' => 'Monthly strategy meeting required',
            ],
            [
                'name' => 'Mountain View Resort', 'contact' => 'Hari Prasad', 'email' => 'hari@mvresort.com', 'phone' => '+977-9841000005',
                'package' => 'standard', 'amount' => 35000,
                'contract_start' => '2026-04-01', 'contract_end' => '2027-03-31', 'status' => 'active',
                'deliverables' => '6 reels, 6 posts, 4 stories per month',
                'brand_guide' => 'Luxury, serene, mountain views, soft colors',
                'social_links' => 'instagram.com/mvresort',
                'notes' => 'Peak season: Oct-Dec',
            ],
            [
                'name' => 'Quick Mart', 'contact' => 'Rina KC', 'email' => 'rina@quickmart.com', 'phone' => '+977-9841000006',
                'package' => 'basic', 'amount' => 15000,
                'contract_start' => '2026-05-01', 'contract_end' => '2026-11-01', 'status' => 'inactive',
                'deliverables' => '4 posts per month',
                'brand_guide' => 'Retail, deals, bright colors',
                'social_links' => 'facebook.com/quickmart',
                'notes' => 'On hold - budget review',
            ],
        ];

        foreach ($rows as $r) {
            DB::table('clients')->insert(array_merge($r, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}
