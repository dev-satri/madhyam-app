<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $titles = ['Edit intro sequence', 'Color grade footage', 'Write caption draft', 'Design thumbnail', 'Schedule posts', 'Record voiceover', 'Client call prep', 'Asset collection', 'Review analytics', 'Update content plan', 'Shoot BTS photos', 'Export final cut', 'Upload to drive', 'Create hashtags', 'Respond to comments'];
        $priorities = ['low', 'medium', 'high'];
        $statuses = ['todo', 'in-progress', 'completed'];
        $progresses = [0, 50, 100];

        $clientIds = DB::table('clients')->orderBy('id')->pluck('id')->all();
        $teamIds = DB::table('users')->where('role', '!=', 'super-admin')->orderBy('id')->pluck('id')->all();
        $c = count($clientIds);
        $t = count($teamIds);

        for ($i = 0; $i < 18; $i++) {
            $isShoot = $i % 4 === 0;
            $due = now()->addDays(random_int(0, 14) - 4)->toDateString();
            DB::table('tasks')->insert([
                'title' => $isShoot ? 'Shoot: '.$titles[$i % count($titles)] : $titles[$i % count($titles)],
                'type' => $isShoot ? 'shoot' : ($i % 3 === 0 ? 'editing' : 'task'),
                'client_id' => $clientIds[$i % $c],
                'assignee' => $teamIds[$i % $t],
                'due_date' => $due,
                'priority' => $priorities[$i % 3],
                'status' => $statuses[$i % 3],
                'description' => null,
                'location' => $isShoot ? 'Thamel, Kathmandu' : null,
                'checklist' => $isShoot ? "Camera\nTripod\nLighting\nAudio\nProps" : null,
                'progress' => $isShoot ? 0 : $progresses[$i % 3],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
