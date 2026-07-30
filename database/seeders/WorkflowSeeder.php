<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test workflow board.
 *
 * 10 workflows total:
 *  - 6 linked to content that has passed Approval #1 (contents in
 *    scripting/revision/published get a workflow row)
 *  - 4 standalone workflows to fill the Kanban with items at every stage
 *    including one overdue and one in "review" (Approval #2 admin-pending)
 *
 * Valid stages (workflow_stages seeded lookup):
 *   todo, in-progress, scripting, review, revision, ready-for-production, published
 */
class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        $c2 = DB::table('clients')->where('name', 'Trek Nepal Adventures')->value('id');

        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->value('id');

        // ── Content-linked workflows (contents past Approval #1) ─────────
        $contentTitles = [
            'Himalayan Coffee — Farm Visit Video' => ['stage' => 'scripting',            'assignee' => $admin, 'priority' => 'high'],
            'Himalayan Coffee — Barista Series Reel' => ['stage' => 'revision',             'assignee' => $admin, 'priority' => 'urgent', 'revision_notes' => 'Please strengthen the hook in the first 3 seconds and update color grade to match brand guidelines.'],
            'Himalayan Coffee — Origin Story' => ['stage' => 'published',            'assignee' => $admin, 'priority' => 'medium'],
            'Trek Nepal — Everest BC Trip Video' => ['stage' => 'scripting',            'assignee' => $admin, 'priority' => 'medium'],
            'Trek Nepal — Guide Testimonial' => ['stage' => 'published',            'assignee' => $admin, 'priority' => 'medium'],
            'Trek Nepal — Autumn Season Carousel' => ['stage' => 'revision',             'assignee' => $admin, 'priority' => 'high',   'revision_notes' => 'Audio levels are inconsistent — please re-edit the middle segment.'],
        ];

        $count = 0;
        foreach ($contentTitles as $title => $wf) {
            $content = DB::table('contents')->where('title', $title)->first();
            if (! $content) {
                continue;
            }

            DB::table('workflows')->insert([
                'title' => $content->title,
                'client_id' => $content->client_id,
                'content_id' => $content->id,
                'type' => $content->type,
                'stage' => $wf['stage'],
                'deadline' => now()->addDays(match ($wf['stage']) {
                    'published' => -3,
                    'revision' => 2,
                    default => 7,
                })->toDateString(),
                'assignee' => $wf['assignee'],
                'priority' => $wf['priority'],
                'notes' => null,
                'tags' => null,
                'status' => $wf['stage'] === 'published' ? 'completed' : 'active',
                'submitted_by' => $admin,
                'revision_notes' => $wf['revision_notes'] ?? null,
                'created_at' => now()->subDays(5),
                'updated_at' => now(),
            ]);
            $count++;
        }

        // ── Standalone workflows: fill remaining stages ──────────────────
        $standalone = [
            ['title' => 'Website Banner Refresh',       'client_id' => $c1, 'type' => 'post',     'stage' => 'todo',                 'assignee' => $admin, 'priority' => 'medium', 'deadline_days' => 10],
            ['title' => 'Product Photography Batch',    'client_id' => $c1, 'type' => 'post',     'stage' => 'in-progress',          'assignee' => $admin, 'priority' => 'high',   'deadline_days' => 5],
            // Overdue item — deadline in the past, still not completed
            ['title' => 'Trek Nepal — Ads Cutdown',     'client_id' => $c2, 'type' => 'video',    'stage' => 'in-progress',          'assignee' => $admin, 'priority' => 'urgent', 'deadline_days' => -2],
            // Workflow at "review" — waiting for Approval #2 (admin-pending)
            ['title' => 'Himalayan Coffee — Winter Campaign', 'client_id' => $c1, 'type' => 'carousel', 'stage' => 'review',        'assignee' => $admin, 'priority' => 'medium', 'deadline_days' => 3],
        ];

        foreach ($standalone as $wf) {
            DB::table('workflows')->insert([
                'title' => $wf['title'],
                'client_id' => $wf['client_id'],
                'content_id' => null,
                'type' => $wf['type'],
                'stage' => $wf['stage'],
                'deadline' => now()->addDays($wf['deadline_days'])->toDateString(),
                'assignee' => $wf['assignee'],
                'priority' => $wf['priority'],
                'notes' => null,
                'tags' => null,
                'status' => 'active',
                'submitted_by' => $admin,
                'revision_notes' => null,
                'created_at' => now()->subDays(3),
                'updated_at' => now(),
            ]);
            $count++;
        }

        $this->command?->info("  ✓ Workflows: {$count} items across every Kanban stage (incl. 1 overdue)");
    }
}
