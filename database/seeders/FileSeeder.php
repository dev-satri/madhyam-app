<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test files.
 *
 * 6 files across the 3 folders covering every file `type`
 * (image / video / audio / document) so the Files page has data
 * in every filter. Paths are placeholders — real uploads happen
 * via the app UI. See docs/FINAL_TEST_PLAN.md > Known Limitations.
 */
class FileSeeder extends Seeder
{
    public function run(): void
    {
        $c1 = DB::table('clients')->where('name', 'Himalayan Coffee Co.')->value('id');
        $c2 = DB::table('clients')->where('name', 'Trek Nepal Adventures')->value('id');

        $admin = DB::table('users')->where('email', 'admin@madhyam.com')->value('id');
        $editor = DB::table('users')->where('email', 'staff.editor@madhyam.com')->value('id');
        $videographer = DB::table('users')->where('email', 'staff.video@madhyam.com')->value('id');

        $himalayanFolder = DB::table('folders')->where('name', 'Himalayan Coffee')->value('id');
        $trekFolder = DB::table('folders')->where('name', 'Trek Nepal Adventures')->value('id');
        $templates = DB::table('folders')->where('name', 'Templates')->value('id');

        $rows = [
            ['name' => 'Logo-HimalayanCoffee.png', 'path' => 'demo/logo-himalayancoffee.png', 'type' => 'image',    'size' => 245000,    'client_id' => $c1,  'folder_id' => $himalayanFolder, 'tags' => 'logo,brand',      'uploaded_by' => $admin],
            ['name' => 'Farm-Visit-Raw.mp4',       'path' => 'demo/farm-visit-raw.mp4',       'type' => 'video',    'size' => 125000000, 'client_id' => $c1,  'folder_id' => $himalayanFolder, 'tags' => 'raw,footage',     'uploaded_by' => $videographer],
            ['name' => 'Brand-Guidelines.pdf',     'path' => 'demo/brand-guidelines.pdf',     'type' => 'document', 'size' => 3200000,   'client_id' => null, 'folder_id' => $templates,       'tags' => 'brand,guide',     'uploaded_by' => $admin],
            ['name' => 'Trek-Guide-Testimonial.mp4', 'path' => 'demo/trek-guide-testimonial.mp4', 'type' => 'video','size' => 45000000,  'client_id' => $c2,  'folder_id' => $trekFolder,      'tags' => 'export,reel',     'uploaded_by' => $editor],
            ['name' => 'Ambient-Music-Track.mp3',  'path' => 'demo/ambient-music-track.mp3',  'type' => 'audio',    'size' => 5600000,   'client_id' => null, 'folder_id' => $templates,       'tags' => 'music',           'uploaded_by' => $editor],
            ['name' => 'Trek-Autumn-Set.zip',      'path' => 'demo/trek-autumn-set.zip',      'type' => 'document', 'size' => 18000000,  'client_id' => $c2,  'folder_id' => $trekFolder,      'tags' => 'assets',          'uploaded_by' => $editor],
        ];

        foreach ($rows as $r) {
            DB::table('files')->insert(array_merge($r, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command?->info('  ✓ Files: 6 across image, video, audio, document');
    }
}
