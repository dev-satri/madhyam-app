<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FileSeeder extends Seeder
{
    public function run(): void
    {
        $c = fn (string $name) => DB::table('clients')->where('name', $name)->value('id');
        $u = fn (string $email) => DB::table('users')->where('email', $email)->value('id');

        $c1 = $c('Himalayan Coffee');
        $c2 = $c('Nepal Trek Adventures');
        $c3 = $c('Kathmandu Bites');
        $c4 = $c('GreenLeaf Organic');

        $designer = $u('priya@madhyam.com');
        $videographer = $u('sita@madhyam.com');
        $editor = $u('anil@madhyam.com');
        $manager = $u('rajesh@madhyam.com');

        $rows = [
            ['name' => 'Logo-HimalayanCoffee.png',   'path' => 'demo/logo-himalayancoffee.png',   'type' => 'image',    'size' => 245000,   'client_id' => $c1,   'tags' => 'logo',            'uploaded_by' => $designer],
            ['name' => 'Banner-FB-Summer.jpg',       'path' => 'demo/banner-fb-summer.jpg',       'type' => 'image',    'size' => 890000,   'client_id' => $c2,   'tags' => 'banner',          'uploaded_by' => $designer],
            ['name' => 'Video-Raw-001.mp4',          'path' => 'demo/video-raw-001.mp4',          'type' => 'video',    'size' => 125000000, 'client_id' => $c1,   'tags' => 'raw,video',       'uploaded_by' => $videographer],
            ['name' => 'Export-Reel-Festival.mp4',   'path' => 'demo/export-reel-festival.mp4',   'type' => 'video',    'size' => 45000000, 'client_id' => $c4,   'tags' => 'export,reel',     'uploaded_by' => $editor],
            ['name' => 'Music-Track-A.mp3',          'path' => 'demo/music-track-a.mp3',          'type' => 'audio',    'size' => 5600000,  'client_id' => null,  'tags' => 'music',           'uploaded_by' => $videographer],
            ['name' => 'Brand-Guidelines-v2.pdf',    'path' => 'demo/brand-guidelines-v2.pdf',    'type' => 'document', 'size' => 3200000,  'client_id' => null,  'tags' => 'brand,guide',     'uploaded_by' => $manager],
            ['name' => 'Thumbnails-Set.zip',         'path' => 'demo/thumbnails-set.zip',         'type' => 'document', 'size' => 18000000, 'client_id' => $c3,   'tags' => 'thumbnails',      'uploaded_by' => $designer],
        ];

        foreach ($rows as $r) {
            $id = DB::table('files')->insertGetId(array_merge($r, [
                'folder_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            DB::table('file_expiries')->insert([
                'file_id' => $id,
                'expiry_date' => now()->addDays(5)->toDateString(),
                'extended' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
