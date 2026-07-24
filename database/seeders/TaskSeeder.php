<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $clientIds = DB::table('clients')->orderBy('id')->pluck('id')->all();
        $userIds = DB::table('users')->where('role', '!=', 'super-admin')->orderBy('id')->pluck('id')->all();
        $workflowIds = DB::table('workflows')->orderBy('id')->pluck('id')->all();
        $c = count($clientIds);
        $u = count($userIds);
        $w = count($workflowIds);

        // ── Linked tasks: attached to workflow items ──
        $linkedTasks = [
            ['title' => 'Record voiceover for brand video', 'type' => 'task', 'priority' => 'high', 'status' => 'in-progress', 'workflowIndex' => 0],
            ['title' => 'Edit intro sequence', 'type' => 'editing', 'priority' => 'medium', 'status' => 'todo', 'workflowIndex' => 0],
            ['title' => 'Color grade festival footage', 'type' => 'editing', 'priority' => 'high', 'status' => 'completed', 'workflowIndex' => 1],
            ['title' => 'Shoot BTS photos', 'type' => 'shoot', 'priority' => 'medium', 'status' => 'in-progress', 'workflowIndex' => 1, 'location' => 'Thamel, Kathmandu'],
            ['title' => 'Write caption for product reel', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'workflowIndex' => 2],
            ['title' => 'Design thumbnail', 'type' => 'task', 'priority' => 'medium', 'status' => 'completed', 'workflowIndex' => 3],
            ['title' => 'Export final cut in 4K', 'type' => 'editing', 'priority' => 'urgent', 'status' => 'todo', 'workflowIndex' => 4],
            ['title' => 'Upload to client drive', 'type' => 'task', 'priority' => 'low', 'status' => 'completed', 'workflowIndex' => 5],
            ['title' => 'Review analytics report', 'type' => 'task', 'priority' => 'medium', 'status' => 'in-progress', 'workflowIndex' => 6],
            ['title' => 'Schedule social posts', 'type' => 'task', 'priority' => 'high', 'status' => 'todo', 'workflowIndex' => 7],
            ['title' => 'Client call prep — ready for production', 'type' => 'task', 'priority' => 'urgent', 'status' => 'in-progress', 'workflowIndex' => 8],
        ];

        foreach ($linkedTasks as $i => $task) {
            $wfId = $workflowIds[$task['workflowIndex']] ?? null;
            $wf = $wfId ? DB::table('workflows')->where('id', $wfId)->first() : null;

            DB::table('tasks')->insert([
                'title' => $task['title'],
                'workflow_id' => $wfId,
                'type' => $task['type'],
                'client_id' => $wf?->client_id ?? $clientIds[$i % $c],
                'assignee' => $userIds[$i % $u],
                'due_date' => now()->addDays(random_int(-3, 14))->toDateString(),
                'priority' => $task['priority'],
                'status' => $task['status'],
                'description' => "Linked to workflow: {$wf?->title}",
                'location' => $task['location'] ?? null,
                'checklist' => $task['type'] === 'shoot' ? "Camera\nTripod\nLighting\nAudio\nProps" : null,
                'progress' => $task['status'] === 'completed' ? 100 : ($task['status'] === 'in-progress' ? 50 : 0),
                'created_at' => now()->subDays(random_int(0, 5)),
                'updated_at' => now(),
            ]);
        }

        // ── Standalone tasks: not linked to any workflow ──
        $standaloneTasks = [
            ['title' => 'Edit intro sequence', 'type' => 'editing', 'priority' => 'medium', 'status' => 'completed'],
            ['title' => 'Color grade footage', 'type' => 'editing', 'priority' => 'high', 'status' => 'completed'],
            ['title' => 'Write caption draft', 'type' => 'task', 'priority' => 'low', 'status' => 'completed'],
            ['title' => 'Design thumbnail', 'type' => 'task', 'priority' => 'medium', 'status' => 'completed'],
            ['title' => 'Schedule posts', 'type' => 'task', 'priority' => 'medium', 'status' => 'completed'],
            ['title' => 'Record voiceover', 'type' => 'task', 'priority' => 'high', 'status' => 'in-progress'],
            ['title' => 'Client call prep', 'type' => 'task', 'priority' => 'medium', 'status' => 'todo'],
            ['title' => 'Asset collection', 'type' => 'task', 'priority' => 'low', 'status' => 'in-progress'],
            ['title' => 'Review analytics', 'type' => 'task', 'priority' => 'medium', 'status' => 'todo'],
            ['title' => 'Update content plan', 'type' => 'task', 'priority' => 'high', 'status' => 'todo'],
            ['title' => 'Shoot: BTS at office', 'type' => 'shoot', 'priority' => 'medium', 'status' => 'completed', 'location' => 'Bouddha, Kathmandu'],
            ['title' => 'Shoot: Product showcase', 'type' => 'shoot', 'priority' => 'high', 'status' => 'in-progress', 'location' => 'Patan, Lalitpur'],
            ['title' => 'Export final cut', 'type' => 'editing', 'priority' => 'urgent', 'status' => 'todo'],
            ['title' => 'Upload to drive', 'type' => 'task', 'priority' => 'low', 'status' => 'completed'],
            ['title' => 'Create hashtags', 'type' => 'task', 'priority' => 'low', 'status' => 'completed'],
            ['title' => 'Respond to comments', 'type' => 'task', 'priority' => 'medium', 'status' => 'in-progress'],
            ['title' => 'Edit reel transitions', 'type' => 'editing', 'priority' => 'medium', 'status' => 'todo'],
            ['title' => 'Shoot: Team group photo', 'type' => 'shoot', 'priority' => 'low', 'status' => 'todo', 'location' => 'Office, Kathmandu'],
        ];

        foreach ($standaloneTasks as $i => $task) {
            $isShoot = $task['type'] === 'shoot';
            DB::table('tasks')->insert([
                'title' => $task['title'],
                'workflow_id' => null,
                'type' => $task['type'],
                'client_id' => $clientIds[$i % $c],
                'assignee' => $userIds[$i % $u],
                'due_date' => now()->addDays(random_int(-4, 14))->toDateString(),
                'priority' => $task['priority'],
                'status' => $task['status'],
                'description' => null,
                'location' => $task['location'] ?? null,
                'checklist' => $isShoot ? "Camera\nTripod\nLighting\nAudio\nProps" : null,
                'progress' => $task['status'] === 'completed' ? 100 : ($task['status'] === 'in-progress' ? 50 : 0),
                'created_at' => now()->subDays(random_int(0, 7)),
                'updated_at' => now(),
            ]);
        }

        $this->command?->info('  ✓ Tasks: ' . count($linkedTasks) . ' linked + ' . count($standaloneTasks) . ' standalone');
    }
}
