<?php

namespace Tests\Feature;

use App\Services\DataBackupService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers todo.md §5.2: Data export/import round-trip.
 *
 * Also serves as a regression guard for the FK-truncate bug fixed in
 * DataBackupService::import (Schema::disableForeignKeyConstraints + delete()).
 */
class DataBackupRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected DataBackupService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->svc = new DataBackupService;
    }

    public function test_export_returns_all_29_tables(): void
    {
        $export = $this->svc->export();
        $this->assertCount(31, $export);
        $this->assertArrayHasKey('users', $export);
        $this->assertArrayHasKey('clients', $export);
        $this->assertArrayHasKey('settings', $export);
    }

    public function test_json_serialize_deserialize_round_trip(): void
    {
        $export = $this->svc->export();
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertEquals(array_keys($export), array_keys($decoded));
        foreach ($export as $table => $rows) {
            $this->assertCount(count($rows), $decoded[$table], "row count mismatch on {$table}");
        }
    }

    public function test_import_restores_identical_table_counts(): void
    {
        $before = $this->svc->getTableCounts();
        $export = $this->svc->export();
        $decoded = json_decode(json_encode($export), true);

        $this->svc->import($decoded);

        $after = $this->svc->getTableCounts();
        foreach ($before as $table => $count) {
            $this->assertSame($count, $after[$table], "table {$table}");
        }
    }

    public function test_import_handles_foreign_key_constraints(): void
    {
        // If FKs weren't disabled, wiping `users` (parent of many tables) would fail
        // with SQLSTATE 42000. The mere fact that import() completes without throwing
        // proves the fix is in place.
        $export = $this->svc->export();
        $this->svc->import(json_decode(json_encode($export), true));

        $this->assertGreaterThan(0, DB::table('users')->count(), 'users must be restored');
        $this->assertGreaterThan(0, DB::table('tasks')->count(), 'tasks must be restored');
    }

    public function test_import_transaction_rolls_back_on_error(): void
    {
        $before = $this->svc->getTableCounts();
        $bad = $this->svc->export();
        // Inject a row with a nonexistent column to force a SQL error mid-import
        $bad['settings'] = [['id' => 1, 'nonexistent_column_xyz' => 'boom']];

        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            $this->svc->import($bad);
        } finally {
            $after = $this->svc->getTableCounts();
            foreach ($before as $table => $count) {
                $this->assertSame(
                    $count,
                    $after[$table],
                    "transaction should have rolled back {$table} — got {$after[$table]}, expected {$count}"
                );
            }
        }
    }

    public function test_import_skips_tables_not_in_payload(): void
    {
        $partial = ['settings' => [['id' => 1, 'agency_name' => 'PartialTest', 'agency_email' => null, 'agency_phone' => null, 'currency' => 'NPR', 'brand_color' => '#000000', 'file_retention_days' => 5, 'base_salary_default' => 25000, 'overtime_rate_default' => 500, 'backup_reminder_days' => 7, 'last_backup_reminder' => null, 'created_at' => now(), 'updated_at' => now()]]];

        $usersBefore = DB::table('users')->count();
        $this->svc->import($partial);

        $this->assertSame($usersBefore, DB::table('users')->count(), 'users table must be untouched when not in payload');
        $this->assertEquals('PartialTest', DB::table('settings')->where('id', 1)->value('agency_name'));
    }
}
