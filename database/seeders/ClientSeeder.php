<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Final-test clients.
 *
 * Exactly 2 clients covering the full domain surface:
 *   1. Himalayan Coffee Co.   — premium package, contract expires in +45 days (healthy)
 *   2. Trek Nepal Adventures  — standard package, contract expires in +20 days
 *      (near-expiry so the ContractExpiryClientNotification / dashboard warning
 *      lights up during manual testing)
 *
 * Idempotent via updateOrInsert on name.
 */
class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $packages = DB::table('packages')->pluck('id', 'slug');

        $rows = [
            [
                'name' => 'Himalayan Coffee Co.',
                'contact' => 'Ram Sharma',
                'email' => 'ram@himalayancoffee.com',
                'phone' => '+977-9841000001',
                'package' => 'premium',
                'amount' => 45000,
                'contract_start' => now()->subDays(45)->toDateString(),
                'contract_end' => now()->addDays(45)->toDateString(),
                'status' => 'active',
                'deliverables' => null,
                'brand_guide' => json_encode(['notes' => 'Warm tones, premium feel, earthy colors, serif fonts.']),
                'social_links' => json_encode([
                    'instagram' => 'instagram.com/himalayancoffee',
                    'facebook' => 'facebook.com/himalayancoffee',
                ]),
                'notes' => 'VIP client — priority handling on all deliverables.',
            ],
            [
                'name' => 'Trek Nepal Adventures',
                'contact' => 'Maya Gurung',
                'email' => 'maya@treknepal.com',
                'phone' => '+977-9841000002',
                'package' => 'standard',
                'amount' => 30000,
                'contract_start' => now()->subDays(70)->toDateString(),
                'contract_end' => now()->addDays(20)->toDateString(),
                'status' => 'active',
                'deliverables' => null,
                'brand_guide' => json_encode(['notes' => 'Adventure vibe, mountains, greens & blues, bold sans-serif.']),
                'social_links' => json_encode([
                    'facebook' => 'facebook.com/treknepal',
                    'instagram' => 'instagram.com/treknepal',
                ]),
                'notes' => 'Contract near expiry — sales team following up for renewal.',
            ],
        ];

        foreach ($rows as $r) {
            DB::table('clients')->updateOrInsert(
                ['name' => $r['name']],
                array_merge($r, [
                    'package_id' => $packages[$r['package']] ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }

        $this->command?->info('  ✓ Clients: 2 (Himalayan Coffee Co. — healthy, Trek Nepal Adventures — contract near expiry)');
    }
}
