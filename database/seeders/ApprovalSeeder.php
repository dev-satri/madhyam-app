<?php

namespace Database\Seeders;

use App\Support\ContentTags;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test approvals.
 *
 * 5 approvals spanning every approval_stage and status combination so
 * both the admin approvals page AND the client portal approvals page
 * have something to review:
 *
 *   1. first  / pending    — new submission from Content Planner
 *   2. admin-pending / pending — waiting for admin sign-off
 *   3. client-pending / pending — waiting for client (client 1 sees this)
 *   4. first  / approved   — historic approved item
 *   5. admin-pending / rejected — sent back for revision
 */
class ApprovalSeeder extends Seeder
{
    public function run(): void
    {
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->value('id');
        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->value('id');

        // Contents at status='in-review' get first-stage pending approvals.
        $inReviewContents = DB::table('contents')->where('status', 'in-review')->get();
        foreach ($inReviewContents as $content) {
            DB::table('approvals')->insert([
                'title' => $content->title,
                'client_id' => $content->client_id,
                'content_id' => $content->id,
                'type' => ContentTags::primary(ContentTags::normalize($content->type, 'type'), 'type'),
                'approval_stage' => 'first',
                'status' => 'pending',
                'submitted_by' => $editor,
                'notes' => 'Please review before this enters the production pipeline.',
                'created_at' => now()->subDays(1),
                'updated_at' => now(),
            ]);
        }

        // Workflow at stage='review' — waiting for admin (Approval #2 admin-pending).
        $reviewWorkflows = DB::table('workflows')->where('stage', 'review')->get();
        foreach ($reviewWorkflows as $wf) {
            DB::table('approvals')->insert([
                'title' => $wf->title,
                'client_id' => $wf->client_id,
                'content_id' => $wf->content_id,
                'type' => $wf->type,
                'approval_stage' => 'admin-pending',
                'status' => 'pending',
                'submitted_by' => $editor,
                'notes' => 'Final internal review before sending to the client.',
                'created_at' => now()->subDays(1),
                'updated_at' => now(),
            ]);
        }

        // Standalone client-pending approval (client 1) — client can approve/reject in portal.
        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        DB::table('approvals')->insert([
            'title' => 'Himalayan Coffee — Diwali Campaign Reel',
            'client_id' => $c1,
            'content_id' => null,
            'type' => 'reel',
            'approval_stage' => 'client-pending',
            'status' => 'pending',
            'submitted_by' => $admin,
            'notes' => 'Ready for client sign-off. Please approve or leave feedback.',
            'created_at' => now()->subHours(4),
            'updated_at' => now(),
        ]);

        // Historic approved (published-story content)
        $published = DB::table('contents')->where('status', 'published')->first();
        if ($published) {
            DB::table('approvals')->insert([
                'title' => $published->title . ' — Admin Review',
                'client_id' => $published->client_id,
                'content_id' => $published->id,
                'type' => ContentTags::primary(ContentTags::normalize($published->type, 'type'), 'type'),
                'approval_stage' => 'first',
                'status' => 'approved',
                'submitted_by' => $editor,
                'notes' => 'Approved and moved to production pipeline.',
                'created_at' => now()->subDays(6),
                'updated_at' => now()->subDays(4),
            ]);
        }

        // Rejected approval — linked to a revision-stage workflow
        $revisionWorkflow = DB::table('workflows')->where('stage', 'revision')->first();
        if ($revisionWorkflow) {
            DB::table('approvals')->insert([
                'title' => $revisionWorkflow->title . ' — Review',
                'client_id' => $revisionWorkflow->client_id,
                'content_id' => $revisionWorkflow->content_id,
                'type' => $revisionWorkflow->type,
                'approval_stage' => 'admin-pending',
                'status' => 'rejected',
                'submitted_by' => $editor,
                'notes' => 'Needs revision before proceeding.',
                'rejection_reason' => $revisionWorkflow->revision_notes ?: 'Does not meet the quality bar — please rework and resubmit.',
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDay(),
            ]);
        }

        $this->command?->info('  ✓ Approvals: seeded across first / admin-pending / client-pending, pending + approved + rejected');
    }
}
