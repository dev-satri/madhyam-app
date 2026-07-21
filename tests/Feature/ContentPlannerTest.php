<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PackageSeeder;
use Database\Seeders\ClientSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers todo.md §5.2: Content planner cartesian-product save creates N rows.
 */
class ContentPlannerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingsSeeder::class, PackageSeeder::class, DepartmentSeeder::class, ClientSeeder::class]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@calendar.test',
            'password' => bcrypt('password'), 'role' => 'super-admin', 'status' => 'active',
        ]);
        $this->clientId = DB::table('clients')->first()->id;
        $this->actingAs($this->admin);
    }

    public function test_cartesian_product_creates_multiple_rows(): void
    {
        Livewire::test('pages.calendar.index')
            ->call('openForm', now()->format('Y-m-d'))
            ->set('title', 'Multi-Platform Post')
            ->set('formClientId', $this->clientId)
            ->set('formDate', now()->format('Y-m-d'))
            ->set('formPlatforms', ['instagram', 'facebook'])
            ->set('formTypes', ['post', 'reel'])
            ->set('formStatus', 'draft')
            ->call('save');

        // 2 platforms × 2 types = 4 rows
        $this->assertDatabaseCount('contents', 4);
        $this->assertEquals(4, DB::table('contents')->where('title', 'Multi-Platform Post')->count());
    }

    public function test_single_platform_single_type_creates_one_row(): void
    {
        Livewire::test('pages.calendar.index')
            ->call('openForm', now()->format('Y-m-d'))
            ->set('title', 'Single Post')
            ->set('formClientId', $this->clientId)
            ->set('formDate', now()->format('Y-m-d'))
            ->set('formPlatforms', ['instagram'])
            ->set('formTypes', ['post'])
            ->set('formStatus', 'draft')
            ->call('save');

        $this->assertDatabaseCount('contents', 1);
    }

    public function test_edit_does_not_create_new_row(): void
    {
        // Seed one content item
        DB::table('contents')->insert([
            'title' => 'Original', 'client_id' => $this->clientId,
            'platform' => 'instagram', 'type' => 'post', 'date' => now()->format('Y-m-d'),
            'status' => 'draft', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('contents')->first()->id;

        Livewire::test('pages.calendar.index')
            ->call('editContent', $id)
            ->set('title', 'Updated Title')
            ->call('save');

        $this->assertDatabaseCount('contents', 1);
        $this->assertEquals('Updated Title', DB::table('contents')->where('id', $id)->value('title'));
    }

    public function test_validation_requires_platforms_and_types(): void
    {
        Livewire::test('pages.calendar.index')
            ->call('openForm', now()->format('Y-m-d'))
            ->set('title', 'No Selection')
            ->set('formClientId', $this->clientId)
            ->set('formDate', now()->format('Y-m-d'))
            ->set('formPlatforms', [])
            ->set('formTypes', [])
            ->set('formStatus', 'draft')
            ->call('save')
            ->assertHasErrors(['formPlatforms', 'formTypes']);
    }

    public function test_content_is_assigned_to_current_user(): void
    {
        Livewire::test('pages.calendar.index')
            ->call('openForm', now()->format('Y-m-d'))
            ->set('title', 'My Content')
            ->set('formClientId', $this->clientId)
            ->set('formDate', now()->format('Y-m-d'))
            ->set('formPlatforms', ['instagram'])
            ->set('formTypes', ['post'])
            ->set('formStatus', 'draft')
            ->call('save');

        $this->assertEquals($this->admin->id, DB::table('contents')->where('title', 'My Content')->value('created_by'));
    }
}
