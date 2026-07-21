<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $stages = ['idea', 'scripting', 'shooting', 'editing', 'review', 'published'];
        $types = ['reel', 'post', 'story', 'video', 'carousel', 'blog'];
        $priorities = ['low', 'medium', 'high', 'urgent'];
        $titles = ['Brand Video Q3', 'Festival Campaign Reel', 'Product Launch Post', 'Customer Testimonial', 'Behind the Scenes', 'Tutorial Series', 'Social Media Ad', 'Newsletter Design', 'Event Coverage', 'Weekly Reel', 'Blog Post', 'Story Campaign', 'Podcast Intro', 'Motion Graphics', 'Photo Shoot', 'Logo Animation', 'Landing Page', 'Email Template', 'Annual Report', 'Case Study'];

        $clientIds = DB::table('clients')->orderBy('id')->pluck('id')->all();
        $teamIds = DB::table('users')->where('role', '!=', 'super-admin')->orderBy('id')->pluck('id')->all();
        $c = count($clientIds);
        $t = count($teamIds);

        for ($i = 0; $i < 20; $i++) {
            $deadline = now()->addDays(random_int(0, 25))->toDateString();
            DB::table('workflows')->insert([
                'title' => $titles[$i],
                'client_id' => $clientIds[$i % $c],
                'type' => $types[$i % 6],
                'stage' => $stages[$i % 6],
                'deadline' => $deadline,
                'assignee' => $teamIds[$i % $t],
                'priority' => $priorities[$i % 4],
                'notes' => null,
                'tags' => null,
                'status' => null,
                'submitted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
