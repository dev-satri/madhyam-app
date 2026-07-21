<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers todo.md §5.2:
 *   - File upload: auto-type detection + expiry = today + retention_days
 *   - File extend: +5 days once, `extended` prevents repeat
 */
class FilesLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(SettingsSeeder::class);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@files.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        $this->actingAs($this->admin);
    }

    public function test_upload_detects_image_type(): void
    {
        $file = UploadedFile::fake()->image('photo.png', 100, 100);

        Livewire::test('pages.files.index')
            ->set('pendingFiles', [$file])
            ->call('uploadFiles');

        $this->assertDatabaseHas('files', [
            'name' => 'photo.png',
            'type' => 'image',
        ]);
    }

    public function test_upload_detects_video_type(): void
    {
        $file = UploadedFile::fake()->create('clip.mp4', 100);

        Livewire::test('pages.files.index')
            ->set('pendingFiles', [$file])
            ->call('uploadFiles');

        $this->assertDatabaseHas('files', [
            'name' => 'clip.mp4',
            'type' => 'video',
        ]);
    }

    public function test_upload_detects_audio_type(): void
    {
        $file = UploadedFile::fake()->create('song.mp3', 50);

        Livewire::test('pages.files.index')
            ->set('pendingFiles', [$file])
            ->call('uploadFiles');

        $this->assertDatabaseHas('files', [
            'name' => 'song.mp3',
            'type' => 'audio',
        ]);
    }

    public function test_upload_defaults_unknown_extension_to_document(): void
    {
        $file = UploadedFile::fake()->create('spec.xyz', 10);

        Livewire::test('pages.files.index')
            ->set('pendingFiles', [$file])
            ->call('uploadFiles');

        $this->assertDatabaseHas('files', [
            'name' => 'spec.xyz',
            'type' => 'document',
        ]);
    }

    public function test_upload_populates_expiry_at_today_plus_retention_days(): void
    {
        // SettingsSeeder default is 5, but assert explicitly
        DB::table('settings')->where('id', 1)->update(['file_retention_days' => 10]);

        $file = UploadedFile::fake()->create('report.pdf', 50);

        Livewire::test('pages.files.index')
            ->set('pendingFiles', [$file])
            ->call('uploadFiles');

        $fileRow = DB::table('files')->where('name', 'report.pdf')->first();
        $this->assertNotNull($fileRow);

        $expiry = DB::table('file_expiries')->where('file_id', $fileRow->id)->first();
        $this->assertNotNull($expiry);
        $this->assertEquals(now()->addDays(10)->toDateString(), \Carbon\Carbon::parse($expiry->expiry_date)->toDateString());
        $this->assertEquals(0, (int) $expiry->extended);
    }

    public function test_extend_expiry_adds_five_days_and_marks_extended(): void
    {
        $fileId = DB::table('files')->insertGetId([
            'name' => 'ext.pdf', 'path' => 'files/ext.pdf', 'type' => 'document',
            'size' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $originalExpiry = now()->addDays(3)->toDateString();
        DB::table('file_expiries')->insert([
            'file_id' => $fileId,
            'expiry_date' => $originalExpiry,
            'extended' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.files.index')->call('extendExpiry', $fileId);

        $expiry = DB::table('file_expiries')->where('file_id', $fileId)->first();
        $this->assertEquals(
            \Carbon\Carbon::parse($originalExpiry)->addDays(5)->toDateString(),
            \Carbon\Carbon::parse($expiry->expiry_date)->toDateString()
        );
        $this->assertEquals(1, (int) $expiry->extended);
    }

    public function test_extend_expiry_is_idempotent_once_extended(): void
    {
        $fileId = DB::table('files')->insertGetId([
            'name' => 'twice.pdf', 'path' => 'files/twice.pdf', 'type' => 'document',
            'size' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $extendedDate = now()->addDays(8)->toDateString();
        DB::table('file_expiries')->insert([
            'file_id' => $fileId,
            'expiry_date' => $extendedDate,
            'extended' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.files.index')->call('extendExpiry', $fileId);

        $expiry = DB::table('file_expiries')->where('file_id', $fileId)->first();
        // Expiry date must NOT have moved on second extend
        $this->assertEquals(
            $extendedDate,
            \Carbon\Carbon::parse($expiry->expiry_date)->toDateString(),
            'extended=true must prevent further extension'
        );
        $this->assertEquals(1, (int) $expiry->extended);
    }
}
