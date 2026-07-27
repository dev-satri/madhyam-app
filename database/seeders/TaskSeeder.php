<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test tasks.
 *
 * 12 tasks distributed across the 4 staff so every user has meaningful
 * data on their dashboard:
 *
 *   staff.editor      — 4 tasks (editing + writing)
 *   staff.video       — 4 tasks (shoots + camera)
 *   admin             — 2 tasks (coordination)
 *   super-admin       — 2 tasks (oversight)
 *
 * Covers every task type (task, shoot, editing) and every status
 * (todo, in-progress, completed) plus one overdue task.
 */
class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        $c2 = DB::table('clients')->where('name', 'Trek Nepal Adventures')->value('id');

        $superAdmin = DB::table('users')->where('email', 'superadmin@madhyam.com')->value('id');
        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->value('id');
        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->value('id');
        $videographer = DB::table('users')->where('email', 'staff.video@madhyam.com')->value('id');

        // First workflow id (used for the linked-task example)
        $firstWorkflow = DB::table('workflows')->orderBy('id')->value('id');

        $tasks = [
            // ── Editor (staff.editor) — 4 ──
            ['title' => 'Edit Farm Visit intro sequence',      'assignee' => $editor,       'client_id' => $c1, 'type' => 'editing', 'priority' => 'high',   'status' => 'in-progress', 'due_days' => 2,  'workflow_id' => $firstWorkflow, 'progress' => 60],
            ['title' => 'Colour grade Barista Series footage', 'assignee' => $editor,       'client_id' => $c1, 'type' => 'editing', 'priority' => 'urgent', 'status' => 'todo',        'due_days' => 3,  'workflow_id' => null,           'progress' => 0],
            ['title' => 'Write captions for Festival Blend',   'assignee' => $editor,       'client_id' => $c1, 'type' => 'task',    'priority' => 'medium', 'status' => 'completed',   'due_days' => -1, 'workflow_id' => null,           'progress' => 100],
            ['title' => 'Draft hashtag set for Autumn post',   'assignee' => $editor,       'client_id' => $c2, 'type' => 'task',    'priority' => 'low',    'status' => 'todo',        'due_days' => 5,  'workflow_id' => null,           'progress' => 0],

            // ── Videographer (staff.video) — 4 ──
            ['title' => 'Shoot BTS at coffee farm',            'assignee' => $videographer, 'client_id' => $c1, 'type' => 'shoot',   'priority' => 'high',   'status' => 'in-progress', 'due_days' => 4,  'workflow_id' => null,           'progress' => 30, 'location' => 'Ilam, Nepal',      'checklist' => "Camera\nTripod\nAudio recorder\nLighting kit\nReleases"],
            ['title' => 'Shoot product close-ups (Winter)',    'assignee' => $videographer, 'client_id' => $c1, 'type' => 'shoot',   'priority' => 'medium', 'status' => 'todo',        'due_days' => 7,  'workflow_id' => null,           'progress' => 0,  'location' => 'Studio, Kathmandu', 'checklist' => "Backdrop\nCamera\n50mm lens\nProduct props"],
            ['title' => 'Ads Cutdown — overdue export',        'assignee' => $videographer, 'client_id' => $c2, 'type' => 'editing', 'priority' => 'urgent', 'status' => 'in-progress', 'due_days' => -3, 'workflow_id' => null,           'progress' => 80],
            ['title' => 'Shoot trek guide testimonial',        'assignee' => $videographer, 'client_id' => $c2, 'type' => 'shoot',   'priority' => 'medium', 'status' => 'completed',   'due_days' => -6, 'workflow_id' => null,           'progress' => 100, 'location' => 'Pokhara',           'checklist' => "Camera\nLavalier mic\nBackdrop"],

            // ── Admin — 2 ──
            ['title' => 'Prep monthly report for Himalayan Coffee', 'assignee' => $admin, 'client_id' => $c1, 'type' => 'task', 'priority' => 'high',   'status' => 'in-progress', 'due_days' => 1, 'workflow_id' => null, 'progress' => 40],
            ['title' => 'Review Trek Nepal renewal offer',          'assignee' => $admin, 'client_id' => $c2, 'type' => 'task', 'priority' => 'urgent', 'status' => 'todo',        'due_days' => 2, 'workflow_id' => null, 'progress' => 0],

            // ── Super Admin — 2 ──
            ['title' => 'Quarterly finance review',                 'assignee' => $superAdmin, 'client_id' => null, 'type' => 'task', 'priority' => 'medium', 'status' => 'todo',      'due_days' => 6, 'workflow_id' => null, 'progress' => 0],
            ['title' => 'Approve July payroll batch',               'assignee' => $superAdmin, 'client_id' => null, 'type' => 'task', 'priority' => 'high',   'status' => 'completed', 'due_days' => -2, 'workflow_id' => null, 'progress' => 100],
        ];

        foreach ($tasks as $t) {
            DB::table('tasks')->insert([
                'title' => $t['title'],
                'workflow_id' => $t['workflow_id'] ?? null,
                'type' => $t['type'],
                'client_id' => $t['client_id'],
                'assignee' => $t['assignee'],
                'due_date' => now()->addDays($t['due_days'])->toDateString(),
                'priority' => $t['priority'],
                'status' => $t['status'],
                'description' => 'Seeded final-test task.',
                'location' => $t['location'] ?? null,
                'checklist' => $t['checklist'] ?? null,
                'progress' => $t['progress'],
                'created_at' => now()->subDays(3),
                'updated_at' => now(),
            ]);
        }

        $this->command?->info('  ✓ Tasks: 12 (4 editor, 4 videographer, 2 admin, 2 super-admin) incl. 1 overdue');
    }
}
