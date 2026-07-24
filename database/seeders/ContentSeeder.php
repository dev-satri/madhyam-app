<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $clientIds = DB::table('clients')->orderBy('id')->pluck('id')->all();
        $userIds = DB::table('users')->where('role', '!=', 'super-admin')->orderBy('id')->pluck('id')->all();
        $c = count($clientIds);
        $u = count($userIds);

        $platforms = ['instagram', 'facebook', 'tiktok', 'youtube'];
        $types = ['reel', 'post', 'story', 'video', 'carousel', 'blog'];

        // ── Pipeline demo: content at every stage of the production flow ──
        $pipeline = [
            // DRAFTS: not submitted yet
            ['title' => 'Summer Sale Announcement', 'status' => 'draft', 'platform' => 'instagram', 'type' => 'carousel'],
            ['title' => 'Behind The Scenes - Office Tour', 'status' => 'draft', 'platform' => 'tiktok', 'type' => 'reel'],
            ['title' => 'Weekly Motivation Post', 'status' => 'draft', 'platform' => 'facebook', 'type' => 'post'],

            // SCRIPTING: being written
            ['title' => 'Product Launch Video', 'status' => 'scripting', 'platform' => 'youtube', 'type' => 'video'],
            ['title' => 'Customer Testimonial Reel', 'status' => 'scripting', 'platform' => 'instagram', 'type' => 'reel'],

            // IN-REVIEW: submitted for Approval #1
            ['title' => 'Festival Campaign Post', 'status' => 'in-review', 'platform' => 'facebook', 'type' => 'post', 'submitted' => true],
            ['title' => 'Brand Story Highlight', 'status' => 'in-review', 'platform' => 'instagram', 'type' => 'story', 'submitted' => true],

            // REVISION: rejected, needs rework
            ['title' => 'Event Promo Reel', 'status' => 'revision', 'platform' => 'instagram', 'type' => 'reel'],
            ['title' => 'Holiday Special Video', 'status' => 'revision', 'platform' => 'youtube', 'type' => 'video'],

            // PUBLISHED: terminal state
            ['title' => 'New Year Campaign', 'status' => 'published', 'platform' => 'facebook', 'type' => 'post'],
            ['title' => 'Team Spotlight Reel', 'status' => 'published', 'platform' => 'instagram', 'type' => 'reel'],
            ['title' => 'Year End Review', 'status' => 'published', 'platform' => 'youtube', 'type' => 'video'],
        ];

        $contentIds = [];
        foreach ($pipeline as $i => $item) {
            $date = now()->addDays(random_int(-5, 30))->toDateString();
            $id = DB::table('contents')->insertGetId([
                'title' => $item['title'],
                'client_id' => $clientIds[$i % $c],
                'platform' => $item['platform'],
                'type' => $item['type'],
                'date' => $date,
                'due_date' => now()->addDays(random_int(1, 14))->toDateString(),
                'status' => $item['status'],
                'caption' => "Sample caption for {$item['title']} #marketing #nepal",
                'hashtags' => '#marketing #nepal #digital #agency',
                'needs_approval' => in_array($item['status'], ['in-review', 'scripting']),
                'submitted_for_approval_at' => ($item['submitted'] ?? false) ? now()->subDays(random_int(0, 3)) : null,
                'created_by' => $userIds[$i % $u],
                'created_at' => now()->subDays(random_int(0, 5)),
                'updated_at' => now(),
            ]);
            $contentIds[] = $id;
        }

        // ── Bulk content: varied statuses for calendar fill ──
        $statuses = ['draft', 'scripting', 'in-review', 'revision', 'published'];
        for ($i = 0; $i < 28; $i++) {
            $date = now()->addDays(random_int(-10, 35))->toDateString();
            $status = $statuses[$i % 5];
            DB::table('contents')->insert([
                'title' => 'Content Post ' . ($i + 1),
                'client_id' => $clientIds[$i % $c],
                'platform' => $platforms[$i % 4],
                'type' => $types[$i % 6],
                'date' => $date,
                'due_date' => now()->addDays(random_int(1, 20))->toDateString(),
                'status' => $status,
                'caption' => 'Scheduled content for the week #socialmedia',
                'hashtags' => '#socialmedia #marketing',
                'needs_approval' => false,
                'created_by' => $userIds[$i % $u],
                'created_at' => now()->subDays(random_int(0, 10)),
                'updated_at' => now(),
            ]);
        }

        // Store content IDs for other seeders
        $this->command?->info('  ✓ Content: ' . count($pipeline) . ' pipeline items + 28 bulk items');
    }
}
