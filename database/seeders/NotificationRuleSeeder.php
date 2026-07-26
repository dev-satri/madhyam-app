<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Shoot Reminder',      'trigger' => 'shoot-reminder',      'days' => 1,  'active' => true],
            ['name' => 'Deadline Alert',      'trigger' => 'deadline-reminder',   'days' => 2,  'active' => true],
            ['name' => 'Contract Expiry',     'trigger' => 'contract-expiry',     'days' => 30, 'active' => true],
            ['name' => 'Payment Reminder',    'trigger' => 'payment-reminder',    'days' => 7,  'active' => true],
            ['name' => 'Package Usage Check', 'trigger' => 'package-usage',       'days' => 1,  'active' => true],
        ];

        foreach ($rows as $r) {
            DB::table('notification_rules')->insert(array_merge($r, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
