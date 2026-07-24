<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ApprovalSeeder extends Seeder
{
    public function run(): void
    {
        $clientIds = DB::table('clients')->orderBy('id')->pluck('id')->all();
        $userIds = DB::table('users')->where('role', '!=', 'super-admin')->orderBy('id')->pluck('id')->all();
        $c = count($clientIds);
        $u = count($userIds);

        // ── Approval #1 (First): linked to in-review content ──
        // These are content items submitted from the Content Planner
        $inReviewContent = DB::table('contents')->where('status', 'in-review')->get();

        foreach ($inReviewContent as $i => $content) {
            DB::table('approvals')->insert([
                'title' => $content->title,
                'client_id' => $content->client_id,
                'content_id' => $content->id,
                'type' => $content->type,
                'approval_stage' => 'first',
                'status' => 'pending',
                'submitted_by' => $userIds[$i % $u],
                'notes' => 'Please review this content before it enters the production pipeline.',
                'created_at' => now()->subDays(random_int(0, 3)),
                'updated_at' => now(),
            ]);
        }

        // ── Approval #2 (Admin-Pending): linked to workflow in "review" stage ──
        // These are workflows that have been moved to Review by the team
        $reviewWorkflows = DB::table('workflows')
            ->where('stage', 'review')
            ->whereNotNull('content_id')
            ->get();

        foreach ($reviewWorkflows as $i => $workflow) {
            $content = DB::table('contents')->where('id', $workflow->content_id)->first();
            DB::table('approvals')->insert([
                'title' => $workflow->title,
                'client_id' => $workflow->client_id,
                'content_id' => $workflow->content_id,
                'type' => $workflow->type,
                'approval_stage' => 'admin-pending',
                'status' => 'pending',
                'submitted_by' => $userIds[($i + 2) % $u],
                'notes' => 'Final review before sending to client for approval.',
                'created_at' => now()->subDays(random_int(0, 2)),
                'updated_at' => now(),
            ]);
        }

        // ── Approval #2 (Client-Pending): admin already approved, waiting for client ──
        $readyWorkflows = DB::table('workflows')
            ->where('stage', 'ready-for-production')
            ->whereNotNull('content_id')
            ->get();

        foreach ($readyWorkflows as $i => $workflow) {
            $content = DB::table('contents')->where('id', $workflow->content_id)->first();
            DB::table('approvals')->insert([
                'title' => $workflow->title,
                'client_id' => $workflow->client_id,
                'content_id' => $workflow->content_id,
                'type' => $workflow->type,
                'approval_stage' => 'client-pending',
                'status' => 'pending',
                'submitted_by' => $userIds[($i + 1) % $u],
                'notes' => 'Waiting for client to approve before publishing.',
                'created_at' => now()->subDays(1),
                'updated_at' => now(),
            ]);
        }

        // ── Completed approvals: approved/rejected items from pipeline ──
        $publishedWorkflows = DB::table('workflows')
            ->where('stage', 'published')
            ->whereNotNull('content_id')
            ->get();

        foreach ($publishedWorkflows as $i => $workflow) {
            $content = DB::table('contents')->where('id', $workflow->content_id)->first();
            // First approval (approved)
            DB::table('approvals')->insert([
                'title' => $workflow->title . ' — Admin Review',
                'client_id' => $workflow->client_id,
                'content_id' => $workflow->content_id,
                'type' => $workflow->type,
                'approval_stage' => 'first',
                'status' => 'approved',
                'submitted_by' => $userIds[$i % $u],
                'notes' => 'Content approved for production pipeline.',
                'created_at' => now()->subDays(random_int(5, 10)),
                'updated_at' => now()->subDays(random_int(2, 5)),
            ]);
            // Second approval — admin (approved)
            DB::table('approvals')->insert([
                'title' => $workflow->title . ' — Final Review',
                'client_id' => $workflow->client_id,
                'content_id' => $workflow->content_id,
                'type' => $workflow->type,
                'approval_stage' => 'client-pending',
                'status' => 'approved',
                'submitted_by' => $userIds[($i + 1) % $u],
                'notes' => 'Final approval — ready for production.',
                'created_at' => now()->subDays(random_int(3, 7)),
                'updated_at' => now()->subDays(random_int(1, 3)),
            ]);
        }

        // ── Rejected approvals: revision items ──
        $revisionWorkflows = DB::table('workflows')
            ->where('stage', 'revision')
            ->whereNotNull('content_id')
            ->get();

        foreach ($revisionWorkflows as $i => $workflow) {
            $content = DB::table('contents')->where('id', $workflow->content_id)->first();
            DB::table('approvals')->insert([
                'title' => $workflow->title . ' — Review',
                'client_id' => $workflow->client_id,
                'content_id' => $workflow->content_id,
                'type' => $workflow->type,
                'approval_stage' => 'admin-pending',
                'status' => 'rejected',
                'submitted_by' => $userIds[($i + 2) % $u],
                'notes' => 'Content needs revision before proceeding.',
                'rejection_reason' => $workflow->revision_notes ?? 'Does not meet quality standards. Please revise.',
                'created_at' => now()->subDays(random_int(3, 8)),
                'updated_at' => now()->subDays(random_int(1, 3)),
            ]);
        }

        // ── Standalone approvals (not linked to content pipeline) ──
        $standalone = [
            ['title' => 'Campaign Brief Approval', 'status' => 'approved', 'approval_stage' => 'first'],
            ['title' => 'Brand Guidelines Update', 'status' => 'pending', 'approval_stage' => 'first'],
            ['title' => 'Q3 Content Strategy', 'status' => 'revision', 'approval_stage' => 'first'],
            ['title' => 'Social Media Calendar', 'status' => 'approved', 'approval_stage' => 'first'],
            ['title' => 'Video Script Draft', 'status' => 'pending', 'approval_stage' => 'first'],
        ];

        foreach ($standalone as $i => $item) {
            DB::table('approvals')->insert([
                'title' => $item['title'],
                'client_id' => $clientIds[$i % $c],
                'content_id' => null,
                'type' => ['post', 'reel', 'video', 'carousel', 'blog'][$i % 5],
                'approval_stage' => $item['approval_stage'],
                'status' => $item['status'],
                'submitted_by' => $userIds[$i % $u],
                'notes' => 'Please review this submission.',
                'created_at' => now()->subDays(random_int(0, 7)),
                'updated_at' => now(),
            ]);
        }

        $this->command?->info('  ✓ Approvals: ' . (count($inReviewContent) + count($reviewWorkflows) + count($readyWorkflows) + (count($publishedWorkflows) * 2) + count($revisionWorkflows) + count($standalone)) . ' total approvals across all pipeline stages');
    }
}
