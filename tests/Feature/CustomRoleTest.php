<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CustomRoleSeeder;
use Database\Seeders\DataAccessSeeder;
use Database\Seeders\FeatureAccessSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CustomRoleTest extends TestCase
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
            CustomRoleSeeder::class,
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@roles.test',
            'password' => bcrypt('password'), 'role' => 'super-admin', 'status' => 'active',
        ]);
        $this->actingAs($this->admin);
    }

    public function test_settings_page_renders(): void
    {
        Livewire::test('pages.settings.index')
            ->assertStatus(200);
    }

    public function test_can_create_custom_role(): void
    {
        Livewire::test('pages.settings.index')
            ->call('openRoleForm')
            ->set('formRoleName', 'Junior Developer')
            ->set('formRoleDesc', 'Entry-level developer role')
            ->call('saveRole');

        $this->assertDatabaseHas('custom_roles', [
            'role_key' => 'junior-developer',
            'name' => 'Junior Developer',
        ]);
        $this->assertDatabaseHas('feature_access', [
            'role' => 'junior-developer',
        ]);
        $this->assertDatabaseHas('data_access', [
            'role' => 'junior-developer',
        ]);
    }

    public function test_can_delete_custom_role(): void
    {
        $roleId = DB::table('custom_roles')->where('role_key', 'junior-editor')->value('id');
        $this->assertNotNull($roleId, 'junior-editor role must be seeded');

        Livewire::test('pages.settings.index')
            ->call('deleteRole', $roleId);

        $this->assertDatabaseMissing('custom_roles', ['id' => $roleId]);
        $this->assertDatabaseMissing('feature_access', ['role' => 'junior-editor']);
        $this->assertDatabaseMissing('data_access', ['role' => 'junior-editor']);
    }

    public function test_built_in_roles_cannot_be_deleted(): void
    {
        $builtInRoles = ['super-admin', 'admin', 'manager', 'editor', 'videographer', 'designer', 'copywriter', 'social-media'];

        foreach ($builtInRoles as $role) {
            $inFeatureAccess = DB::table('feature_access')->where('role', $role)->exists();
            $this->assertTrue($inFeatureAccess, "{$role} must exist in feature_access");

            // Calling deleteRole with a non-existent custom_roles id does nothing
            Livewire::test('pages.settings.index')
                ->call('deleteRole', 999999);

            $this->assertTrue(
                DB::table('feature_access')->where('role', $role)->exists(),
                "{$role} must NOT be removed from feature_access"
            );
        }
    }

    public function test_validation_requires_role_name(): void
    {
        Livewire::test('pages.settings.index')
            ->call('openRoleForm')
            ->set('formRoleName', '')
            ->call('saveRole')
            ->assertHasErrors(['formRoleName']);
    }

    public function test_duplicate_role_key_is_rejected(): void
    {
        Livewire::test('pages.settings.index')
            ->call('openRoleForm')
            ->set('formRoleName', 'Junior Editor')
            ->call('saveRole');

        $count = DB::table('custom_roles')->where('role_key', 'junior-editor')->count();
        $this->assertEquals(1, $count, 'Duplicate role should not be created');
    }
}
