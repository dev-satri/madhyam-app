<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers todo.md §5.4: `SendBackupReminderCommand` — updates flag when interval elapsed.
 */
class SendBackupReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
    }

    public function test_sets_reminder_when_never_sent_before(): void
    {
        DB::table('settings')->where('id', 1)->update([
            'last_backup_reminder' => null,
            'backup_reminder_days' => 7,
        ]);

        $this->artisan('backup:remind')
            ->expectsOutputToContain('Backup reminder flag updated.')
            ->assertSuccessful();

        $row = DB::table('settings')->where('id', 1)->first();
        $this->assertNotNull($row->last_backup_reminder, 'last_backup_reminder should be set');
    }

    public function test_updates_reminder_when_interval_elapsed(): void
    {
        $tenDaysAgo = now()->subDays(10);
        DB::table('settings')->where('id', 1)->update([
            'last_backup_reminder' => $tenDaysAgo,
            'backup_reminder_days' => 7,
        ]);

        $this->artisan('backup:remind')
            ->expectsOutputToContain('Backup reminder flag updated.')
            ->assertSuccessful();

        $row = DB::table('settings')->where('id', 1)->first();
        $this->assertNotNull($row->last_backup_reminder);
        $this->assertTrue(
            now()->diffInMinutes($row->last_backup_reminder) < 5,
            'last_backup_reminder should be freshly updated to ~now'
        );
    }

    public function test_skips_when_reminder_within_interval(): void
    {
        $twoDaysAgo = now()->subDays(2);
        DB::table('settings')->where('id', 1)->update([
            'last_backup_reminder' => $twoDaysAgo,
            'backup_reminder_days' => 7,
        ]);

        $this->artisan('backup:remind')
            ->expectsOutputToContain('Backup reminder already sent within the interval. Skipping.')
            ->assertSuccessful();

        $row = DB::table('settings')->where('id', 1)->first();
        // Value should still be the same (unchanged)
        $this->assertEquals(
            $twoDaysAgo->format('Y-m-d H:i:s'),
            Carbon::parse($row->last_backup_reminder)->format('Y-m-d H:i:s'),
            'last_backup_reminder should be unchanged when skipped'
        );
    }

    public function test_fails_gracefully_when_settings_row_missing(): void
    {
        DB::table('settings')->where('id', 1)->delete();

        $this->artisan('backup:remind')
            ->expectsOutputToContain('Settings row not found.')
            ->assertFailed();
    }
}
