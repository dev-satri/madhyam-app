<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $platforms = ['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'linkedin'];
        $types = ['reel', 'post', 'story', 'video', 'carousel', 'blog'];
        $statuses = ['draft', 'scheduled', 'published'];
        $titles = ['Festival Promo', 'Product Showcase', 'Behind The Scenes', 'Customer Story', 'Tips & Tricks', 'Brand Reel', 'Team Spotlight', 'Season Special', 'Tutorial', 'Announcement'];

        $clientIds = DB::table('clients')->orderBy('id')->pluck('id')->all();
        $c = count($clientIds);

        for ($i = 0; $i < 40; $i++) {
            $date = now()->addDays(random_int(0, 40) - 10)->toDateString();
            DB::table('contents')->insert([
                'title' => $titles[$i % 10].' '.($i + 1),
                'client_id' => $clientIds[$i % $c],
                'platform' => $platforms[$i % 6],
                'type' => $types[$i % 6],
                'date' => $date,
                'status' => $statuses[$i % 3],
                'caption' => 'Amazing content for our audience #marketing #nepal',
                'hashtags' => '#marketing #nepal #digital #agency',
                'needs_approval' => false,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
