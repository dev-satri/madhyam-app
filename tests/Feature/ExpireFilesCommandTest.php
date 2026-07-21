<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers todo.md §5.4: `ExpireFilesCommand` — deletes files past expiry, keeps unexpired.
 */
class ExpireFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_deletes_expired_files_and_their_records(): void
    {
        $path = UploadedFile::fake()->create('expired.pdf', 10)->store('files');
        $fileId = DB::table('files')->insertGetId([
            'name' => 'expired.pdf',
            'path' => $path,
            'type' => 'document',
            'size' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('file_expiries')->insert([
            'file_id' => $fileId,
            'expiry_date' => now()->subDay()->toDateString(),
            'extended' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('files:expire')
            ->expectsOutputToContain('Deleted 1 expired file(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('files', ['id' => $fileId]);
        $this->assertDatabaseMissing('file_expiries', ['file_id' => $fileId]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_keeps_unexpired_files(): void
    {
        $path = UploadedFile::fake()->create('fresh.pdf', 10)->store('files');
        $fileId = DB::table('files')->insertGetId([
            'name' => 'fresh.pdf',
            'path' => $path,
            'type' => 'document',
            'size' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('file_expiries')->insert([
            'file_id' => $fileId,
            'expiry_date' => now()->addDays(5)->toDateString(),
            'extended' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('files:expire')
            ->expectsOutputToContain('Deleted 0 expired file(s).')
            ->assertSuccessful();

        $this->assertDatabaseHas('files', ['id' => $fileId]);
        $this->assertDatabaseHas('file_expiries', ['file_id' => $fileId]);
        Storage::disk('local')->assertExists($path);
    }

    public function test_mixed_batch_only_deletes_expired(): void
    {
        // Expired
        $oldPath = UploadedFile::fake()->create('old.jpg', 5)->store('files');
        $oldId = DB::table('files')->insertGetId([
            'name' => 'old.jpg', 'path' => $oldPath, 'type' => 'image', 'size' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('file_expiries')->insert([
            'file_id' => $oldId, 'expiry_date' => now()->subDays(3)->toDateString(),
            'extended' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Not expired
        $newPath = UploadedFile::fake()->create('new.jpg', 5)->store('files');
        $newId = DB::table('files')->insertGetId([
            'name' => 'new.jpg', 'path' => $newPath, 'type' => 'image', 'size' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('file_expiries')->insert([
            'file_id' => $newId, 'expiry_date' => now()->addDays(2)->toDateString(),
            'extended' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('files:expire')
            ->expectsOutputToContain('Deleted 1 expired file(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('files', ['id' => $oldId]);
        $this->assertDatabaseHas('files', ['id' => $newId]);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_no_expired_rows_reports_zero(): void
    {
        $this->artisan('files:expire')
            ->expectsOutputToContain('Deleted 0 expired file(s).')
            ->assertSuccessful();
    }
}
