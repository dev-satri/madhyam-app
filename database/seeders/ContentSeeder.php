<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test content pipeline.
 *
 * 10 content items distributed across the 2 clients, one item in every
 * production status so each column of the Content Planner and Workflow
 * board has something to test:
 *
 *   draft, scripting, in-review, revision, published
 *
 * Items in "in-review" have submitted_for_approval_at set — that triggers
 * the linked ApprovalSeeder rows (Approval #1 pending).
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        $c2 = DB::table('clients')->where('name', 'Trek Nepal Adventures')->value('id');

        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->value('id');
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->value('id');

        $items = [
            // ── Himalayan Coffee (client 1 — premium, healthy) ────────────
            ['title' => 'Himalayan Coffee — Morning Ritual Reel',   'client_id' => $c1, 'platform' => 'instagram', 'type' => 'reel',     'status' => 'draft',     'created_by' => $editor],
            ['title' => 'Himalayan Coffee — Farm Visit Video',      'client_id' => $c1, 'platform' => 'youtube',   'type' => 'video',    'status' => 'scripting', 'created_by' => $editor],
            ['title' => 'Himalayan Coffee — Festival Blend Post',   'client_id' => $c1, 'platform' => 'facebook',  'type' => 'post',     'status' => 'in-review', 'created_by' => $editor, 'submitted' => true],
            ['title' => 'Himalayan Coffee — Barista Series Reel',   'client_id' => $c1, 'platform' => 'instagram', 'type' => 'reel',     'status' => 'revision',  'created_by' => $admin],
            ['title' => 'Himalayan Coffee — Origin Story',          'client_id' => $c1, 'platform' => 'youtube',   'type' => 'video',    'status' => 'published', 'created_by' => $editor],
            ['title' => 'Himalayan Coffee — Weekend Special',       'client_id' => $c1, 'platform' => 'instagram', 'type' => 'story',    'status' => 'draft',     'created_by' => $admin],

            // ── Trek Nepal Adventures (client 2 — standard, near-expiry) ─
            ['title' => 'Trek Nepal — Monsoon Safety Reel',         'client_id' => $c2, 'platform' => 'instagram', 'type' => 'reel',     'status' => 'in-review', 'created_by' => $editor, 'submitted' => true],
            ['title' => 'Trek Nepal — Everest BC Trip Video',       'client_id' => $c2, 'platform' => 'youtube',   'type' => 'video',    'status' => 'scripting', 'created_by' => $editor],
            ['title' => 'Trek Nepal — Guide Testimonial',           'client_id' => $c2, 'platform' => 'facebook',  'type' => 'post',     'status' => 'published', 'created_by' => $admin],
            ['title' => 'Trek Nepal — Autumn Season Carousel',      'client_id' => $c2, 'platform' => 'instagram', 'type' => 'carousel', 'status' => 'revision',  'created_by' => $editor],
        ];

        foreach ($items as $i => $item) {
            DB::table('contents')->insert([
                'title' => $item['title'],
                'client_id' => $item['client_id'],
                'platform' => $item['platform'],
                'type' => $item['type'],
                'date' => now()->addDays($i - 3)->toDateString(),
                'due_date' => now()->addDays($i + 4)->toDateString(),
                'status' => $item['status'],
                'caption' => 'Sample caption for ' . $item['title'] . ' — #madhyam #nepal',
                'hashtags' => '#madhyam #nepal #marketing #digital',
                'needs_approval' => in_array($item['status'], ['in-review', 'scripting'], true),
                'submitted_for_approval_at' => ($item['submitted'] ?? false) ? now()->subDays(1) : null,
                'created_by' => $item['created_by'],
                'created_at' => now()->subDays(7 - min($i, 7)),
                'updated_at' => now(),
            ]);
        }

        $this->command?->info('  ✓ Contents: 10 items across all pipeline stages');
    }
}
