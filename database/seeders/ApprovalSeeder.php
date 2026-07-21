<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ApprovalSeeder extends Seeder
{
    public function run(): void
    {
        $titles = ['Reel Draft - Himalayan', 'Post Carousel - Trek Nepal', 'Video Edit - GreenLeaf', 'Story Set - KTM Bites', 'Blog Draft - Resort', 'Ad Creative - GreenLeaf', 'Thumbnail Design', 'Campaign Brief', 'Product Reel', 'Season Story'];
        $statuses = ['pending', 'approved', 'revision', 'rejected', 'pending', 'approved', 'revision', 'approved', 'pending', 'approved'];
        $types = ['reel', 'post', 'story', 'video', 'carousel', 'blog'];

        $clientIds = DB::table('clients')->orderBy('id')->pluck('id')->all();
        $teamIds = DB::table('users')->where('role', '!=', 'super-admin')->orderBy('id')->pluck('id')->all();
        $c = count($clientIds);
        $t = count($teamIds);

        for ($i = 0; $i < 10; $i++) {
            DB::table('approvals')->insert([
                'title' => $titles[$i],
                'client_id' => $clientIds[$i % $c],
                'type' => $types[$i % 6],
                'status' => $statuses[$i],
                'submitted_by' => $teamIds[$i % $t],
                'notes' => 'Please review the latest draft for quality.',
                'reference_file' => null,
                'created_at' => now()->subDays(random_int(0, 10)),
                'updated_at' => now(),
            ]);
        }
    }
}
