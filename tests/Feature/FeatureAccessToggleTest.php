<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\DataAccessSeeder;
use Database\Seeders\FeatureAccessSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class FeatureAccessToggleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            SettingsSeeder::class,
            FeatureAccessSeeder::class,
            DataAccessSeeder::class,
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@features.test',
            'password' => bcrypt('password'), 'role' => 'super-admin', 'status' => 'active',
        ]);
        $this->actingAs($this->admin);
    }

    public function test_settings_page_renders(): void
    {
        Livewire::test('pages.settings.index')
            ->assertStatus(200);
    }

    public function test_can_toggle_feature_access(): void
    {
        $editorFeatures = json_decode(
            DB::table('feature_access')->where('role', 'editor')->value('features'),
            true
        );
        $this->assertTrue($editorFeatures['files'], 'editor should start with files enabled');

        Livewire::test('pages.settings.index')
            ->call('toggleFeature', 'editor', 'files');

        $updated = json_decode(
            DB::table('feature_access')->where('role', 'editor')->value('features'),
            true
        );
        $this->assertFalse($updated['files'], 'editor files should be disabled after toggle');
    }

    public function test_toggle_saves_to_database(): void
    {
        Livewire::test('pages.settings.index')
            ->call('toggleFeature', 'editor', 'reports');

        $row = DB::table('feature_access')->where('role', 'editor')->first();
        $features = json_decode($row->features, true);
        $this->assertTrue($features['reports'], 'reports should be enabled after toggle');
        $this->assertNotNull($row->updated_at, 'updated_at should be set');
    }

    public function test_toggle_clears_cache(): void
    {
        Cache::store('array')->put('features:editor', true, 60);

        Livewire::test('pages.settings.index')
            ->call('toggleFeature', 'editor', 'reports');

        $this->assertNull(
            Cache::store('array')->get('features:editor'),
            'Cache should be cleared after toggle'
        );
    }

    public function test_super_admin_always_has_all_features(): void
    {
        $superFeatures = json_decode(
            DB::table('feature_access')->where('role', 'super-admin')->value('features'),
            true
        );

        foreach (RbacService::FEATURES as $feature) {
            $this->assertTrue(
                $superFeatures[$feature] ?? false,
                "super-admin must have feature '{$feature}'"
            );
        }
    }

    public function test_super_admin_feature_matrix_shows_always_in_view(): void
    {
        Livewire::test('pages.settings.index')
            ->set('activeTab', 'feature-access')
            ->assertSee('Always');
    }

    public function test_all_built_in_roles_appear_in_matrix(): void
    {
        Livewire::test('pages.settings.index')
            ->set('activeTab', 'feature-access')
            ->assertSee('super admin')
            ->assertSee('admin')
            ->assertSee('manager')
            ->assertSee('editor');
    }

    public function test_toggle_does_not_affect_other_roles(): void
    {
        $designerBefore = json_decode(
            DB::table('feature_access')->where('role', 'designer')->value('features'),
            true
        );

        Livewire::test('pages.settings.index')
            ->call('toggleFeature', 'editor', 'reports');

        $designerAfter = json_decode(
            DB::table('feature_access')->where('role', 'designer')->value('features'),
            true
        );
        $this->assertEquals($designerBefore, $designerAfter, 'Other roles should not be affected');
    }
}
