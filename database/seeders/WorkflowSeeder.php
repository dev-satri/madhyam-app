<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $clientIds = DB::table('clients')->orderBy('id')->pluck('id')->all();
        $userIds = DB::table('users')->where('role', '!=', 'super-admin')->orderBy('id')->pluck('id')->all();
        $c = count($clientIds);
        $u = count($userIds);

        // Get content IDs that are in-review (these have Approval #1 pending — no workflow yet)
        $inReviewContentIds = DB::table('contents')->where('status', 'in-review')->pluck('id')->all();
        // Get content IDs that are published (already done — no workflow needed)
        $publishedContentIds = DB::table('contents')->where('status', 'published')->pluck('id')->all();
        // Get all content IDs for linking
        $allContentIds = DB::table('contents')->orderBy('id')->pluck('id')->all();

        $validStages = ['todo', 'in-progress', 'scripting', 'review', 'revision', 'ready-for-production', 'published'];

        // ── Pipeline workflow items: linked to content at correct stages ──
        // These represent content that has passed Approval #1 and is in the workflow pipeline
        $pipelineWorkflows = [
            // Content in REVISION stage (rejected by admin)
            ['contentIndex' => 0, 'stage' => 'revision', 'revision_notes' => 'Need stronger hook in the first 3 seconds. Also update the color grading to match brand guidelines.'],
            ['contentIndex' => 1, 'stage' => 'revision', 'revision_notes' => 'Audio levels are inconsistent. Please re-edit the middle section.'],

            // Content in TODO stage (just approved, waiting to start)
            ['contentIndex' => 2, 'stage' => 'todo'],
            ['contentIndex' => 3, 'stage' => 'todo'],

            // Content in IN-PROGRESS
            ['contentIndex' => 4, 'stage' => 'in-progress'],
            ['contentIndex' => 5, 'stage' => 'in-progress'],

            // Content in SCRIPTING
            ['contentIndex' => 6, 'stage' => 'scripting'],

            // Content in REVIEW (waiting for Approval #2 — admin pending)
            ['contentIndex' => 7, 'stage' => 'review'],

            // Content in READY-FOR-PRODUCTION (client approved, admin needs to publish)
            ['contentIndex' => 8, 'stage' => 'ready-for-production'],

            // Content in PUBLISHED (fully done)
            ['contentIndex' => 9, 'stage' => 'published'],
            ['contentIndex' => 10, 'stage' => 'published'],
        ];

        $workflowIds = [];
        foreach ($pipelineWorkflows as $i => $wf) {
            $contentId = $allContentIds[$wf['contentIndex']] ?? null;
            $content = $contentId ? DB::table('contents')->where('id', $contentId)->first() : null;

            $id = DB::table('workflows')->insertGetId([
                'title' => $content?->title ?? 'Workflow Item ' . ($i + 1),
                'client_id' => $content?->client_id ?? $clientIds[$i % $c],
                'content_id' => $contentId,
                'type' => $content?->type ?? 'post',
                'stage' => $wf['stage'],
                'deadline' => now()->addDays(random_int(1, 21))->toDateString(),
                'assignee' => $userIds[$i % $u],
                'priority' => ['low', 'medium', 'high', 'urgent'][$i % 4],
                'revision_notes' => $wf['revision_notes'] ?? null,
                'submitted_by' => $userIds[($i + 1) % $u],
                'created_at' => now()->subDays(random_int(0, 7)),
                'updated_at' => now(),
            ]);
            $workflowIds[] = $id;
        }

        // ── Bulk workflows: varied stages for Kanban fill ──
        for ($i = 0; $i < 14; $i++) {
            $stage = $validStages[$i % 7];
            DB::table('workflows')->insert([
                'title' => 'Workflow Project ' . ($i + 1),
                'client_id' => $clientIds[$i % $c],
                'content_id' => null,
                'type' => ['reel', 'post', 'video', 'carousel'][$i % 4],
                'stage' => $stage,
                'deadline' => now()->addDays(random_int(0, 21))->toDateString(),
                'assignee' => $userIds[$i % $u],
                'priority' => ['low', 'medium', 'high', 'urgent'][$i % 4],
                'revision_notes' => $stage === 'revision' ? 'Please revise the pacing and add brand intro.' : null,
                'submitted_by' => $userIds[($i + 1) % $u],
                'created_at' => now()->subDays(random_int(0, 10)),
                'updated_at' => now(),
            ]);
        }

        $this->command?->info('  ✓ Workflows: ' . count($pipelineWorkflows) . ' pipeline items + 14 bulk items');
    }
}
