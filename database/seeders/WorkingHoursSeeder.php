<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkingHoursSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['day' => 'mon', 'start' => '09:00', 'end' => '18:00', 'active' => true],
            ['day' => 'tue', 'start' => '09:00', 'end' => '18:00', 'active' => true],
            ['day' => 'wed', 'start' => '09:00', 'end' => '18:00', 'active' => true],
            ['day' => 'thu', 'start' => '09:00', 'end' => '18:00', 'active' => true],
            ['day' => 'fri', 'start' => '09:00', 'end' => '18:00', 'active' => true],
            ['day' => 'sat', 'start' => '10:00', 'end' => '14:00', 'active' => true],
            ['day' => 'sun', 'start' => '00:00', 'end' => '00:00', 'active' => false],
        ];
        foreach ($rows as $r) {
            DB::table('working_hours')->insert(array_merge($r, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }
}
