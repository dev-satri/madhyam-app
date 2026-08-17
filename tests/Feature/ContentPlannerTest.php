<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ContentTags;
use Database\Seeders\ClientSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PackageSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers content planner save/edit with JSON array platform + type columns.
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

    public function test_multiple_platforms_and_types_creates_single_row(): void
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

        // New behaviour: one row with JSON arrays, not a cartesian product
        $this->assertDatabaseCount('contents', 1);

        $row = DB::table('contents')->where('title', 'Multi-Platform Post')->first();
        $this->assertEqualsCanonicalizing(['instagram', 'facebook'], json_decode($row->platform, true));
        $this->assertEqualsCanonicalizing(['post', 'reel'], json_decode($row->type, true));
    }

    public function test_all_platform_sentinel_creates_single_row(): void
    {
        Livewire::test('pages.calendar.index')
            ->call('openForm', now()->format('Y-m-d'))
            ->set('title', 'All Platforms Post')
            ->set('formClientId', $this->clientId)
            ->set('formDate', now()->format('Y-m-d'))
            ->set('formPlatforms', [ContentTags::ALL])
            ->set('formTypes', ['post'])
            ->set('formStatus', 'draft')
            ->call('save');

        $this->assertDatabaseCount('contents', 1);

        $row = DB::table('contents')->where('title', 'All Platforms Post')->first();
        $this->assertEquals([ContentTags::ALL], json_decode($row->platform, true));
        $this->assertEquals(['post'], json_decode($row->type, true));
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

        $row = DB::table('contents')->where('title', 'Single Post')->first();
        $this->assertEquals(['instagram'], json_decode($row->platform, true));
        $this->assertEquals(['post'], json_decode($row->type, true));
    }

    public function test_edit_updates_existing_row(): void
    {
        DB::table('contents')->insert([
            'title' => 'Original', 'client_id' => $this->clientId,
            'platform' => json_encode(['instagram']),
            'type' => json_encode(['post']),
            'date' => now()->format('Y-m-d'),
            'status' => 'draft', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('contents')->first()->id;

        // Chain all operations to maintain component state
        $component = Livewire::test('pages.calendar.index')
            ->call('editContent', $id)
            ->assertSet('editingId', $id)
            ->assertSet('title', 'Original')  // Check it loaded correctly
            ->set('title', 'Updated Title')
            ->assertSet('title', 'Updated Title')  // Check it was set
            ->set('formPlatforms', ['facebook', 'tiktok'])
            ->set('formTypes', ['reel'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');  // Check what toast was dispatched

        $this->assertDatabaseCount('contents', 1);
        $row = DB::table('contents')->where('id', $id)->first();
        $this->assertEquals('Updated Title', $row->title);
        $this->assertEqualsCanonicalizing(['facebook', 'tiktok'], json_decode($row->platform, true));
        $this->assertEquals(['reel'], json_decode($row->type, true));
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

    public function test_normalize_collapses_all_with_individual(): void
    {
        Livewire::test('pages.calendar.index')
            ->call('openForm', now()->format('Y-m-d'))
            ->set('title', 'All Override')
            ->set('formClientId', $this->clientId)
            ->set('formDate', now()->format('Y-m-d'))
            ->set('formPlatforms', ['instagram', ContentTags::ALL])
            ->set('formTypes', ['post'])
            ->set('formStatus', 'draft')
            ->call('save');

        $row = DB::table('contents')->where('title', 'All Override')->first();
        // "All" + individual should collapse to just ["all"]
        $this->assertEquals([ContentTags::ALL], json_decode($row->platform, true));
    }

    public function test_edit_normalizes_json_from_database(): void
    {
        // Insert a row with a JSON array platform directly
        DB::table('contents')->insert([
            'title' => 'Pre-existing', 'client_id' => $this->clientId,
            'platform' => json_encode(['youtube', 'linkedin']),
            'type' => json_encode(['video']),
            'date' => now()->format('Y-m-d'),
            'status' => 'draft', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('contents')->first()->id;

        $component = Livewire::test('pages.calendar.index')
            ->call('editContent', $id);

        // The form should load the normalized arrays
        $component->assertSet('formPlatforms', ['youtube', 'linkedin']);
        $component->assertSet('formTypes', ['video']);
    }

    public function test_create_workflow_immediately_with_selected_priority(): void
    {
        Livewire::test('pages.calendar.index')
            ->call('openForm', now()->format('Y-m-d'))
            ->set('title', 'Direct Workflow Item')
            ->set('formClientId', $this->clientId)
            ->set('formDate', now()->format('Y-m-d'))
            ->set('formPlatforms', ['instagram'])
            ->set('formTypes', ['post'])
            ->set('formStatus', 'draft')
            ->set('skipApproval', true)
            ->set('workflowPriority', 'high')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('contents', 1);
        $content = DB::table('contents')->where('title', 'Direct Workflow Item')->first();
        $this->assertEquals('in-review', $content->status);

        $this->assertDatabaseCount('workflows', 1);
        $workflow = DB::table('workflows')->where('content_id', $content->id)->first();
        $this->assertEquals('high', $workflow->priority);
    }
}
