<?php

namespace Tests\Unit;

use App\Models\DataAccess;
use App\Models\FeatureAccess;
use App\Models\User;
use App\Services\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RbacService $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rbac = new RbacService;
    }

    public function test_super_admin_has_all_features(): void
    {
        $features = RbacService::FEATURES;
        foreach ($features as $feature) {
            $this->assertTrue(
                $this->rbac->hasFeature('super-admin', $feature),
                "super-admin should have feature: {$feature}"
            );
        }
    }

    public function test_super_admin_has_all_permissions(): void
    {
        $permissions = RbacService::PERMISSIONS;
        foreach ($permissions as $perm) {
            $this->assertTrue(
                $this->rbac->hasDataAccess('super-admin', $perm),
                "super-admin should have permission: {$perm}"
            );
        }
    }

    public function test_unknown_role_has_no_features(): void
    {
        $this->assertFalse($this->rbac->hasFeature('nonexistent-role', 'dashboard'));
    }

    public function test_unknown_role_has_no_permissions(): void
    {
        $this->assertFalse($this->rbac->hasDataAccess('nonexistent-role', 'seeAllTasks'));
    }

    public function test_null_role_returns_false(): void
    {
        $this->assertFalse($this->rbac->hasFeature(null, 'dashboard'));
        $this->assertFalse($this->rbac->hasDataAccess(null, 'seeAllTasks'));
    }

    public function test_role_with_feature_access(): void
    {
        FeatureAccess::create([
            'role' => 'editor',
            'features' => ['dashboard' => true, 'tasks' => true, 'clients' => false],
        ]);

        $this->assertTrue($this->rbac->hasFeature('editor', 'dashboard'));
        $this->assertTrue($this->rbac->hasFeature('editor', 'tasks'));
        $this->assertFalse($this->rbac->hasFeature('editor', 'clients'));
    }

    public function test_role_with_data_access(): void
    {
        DataAccess::create([
            'role' => 'editor',
            'permissions' => ['seeAllTasks' => false, 'canAddTasks' => true],
        ]);

        $this->assertFalse($this->rbac->hasDataAccess('editor', 'seeAllTasks'));
        $this->assertTrue($this->rbac->hasDataAccess('editor', 'canAddTasks'));
    }

    public function test_is_super_admin(): void
    {
        $superAdmin = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'role' => 'super-admin']);
        $editor = User::create(['name' => 'Editor', 'email' => 'editor@test.com', 'password' => 'password', 'role' => 'editor']);

        $this->assertTrue($this->rbac->isSuperAdmin($superAdmin));
        $this->assertFalse($this->rbac->isSuperAdmin($editor));
        $this->assertFalse($this->rbac->isSuperAdmin(null));
    }

    public function test_can_edit_member(): void
    {
        $superAdmin = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'role' => 'super-admin']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password', 'role' => 'admin']);
        $manager = User::create(['name' => 'Manager', 'email' => 'manager@test.com', 'password' => 'password', 'role' => 'manager']);
        $editor = User::create(['name' => 'Editor', 'email' => 'editor@test.com', 'password' => 'password', 'role' => 'editor']);

        $this->assertTrue($this->rbac->canEditMember($superAdmin, $editor));
        $this->assertTrue($this->rbac->canEditMember($admin, $editor));
        $this->assertTrue($this->rbac->canEditMember($manager, $editor));
        $this->assertFalse($this->rbac->canEditMember($editor, $admin));
    }

    public function test_can_edit_member_cannot_edit_super_admin(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password', 'role' => 'admin']);
        $superAdmin = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'role' => 'super-admin']);

        $this->assertFalse($this->rbac->canEditMember($admin, $superAdmin));
    }

    public function test_can_delete_member(): void
    {
        $superAdmin = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'role' => 'super-admin']);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password', 'role' => 'admin']);
        $editor = User::create(['name' => 'Editor', 'email' => 'editor@test.com', 'password' => 'password', 'role' => 'editor']);

        $this->assertTrue($this->rbac->canDeleteMember($superAdmin, $editor));
        $this->assertTrue($this->rbac->canDeleteMember($admin, $editor));
        $this->assertFalse($this->rbac->canDeleteMember($editor, $admin));
    }

    public function test_can_delete_member_cannot_delete_super_admin(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password', 'role' => 'admin']);
        $superAdmin = User::create(['name' => 'Super', 'email' => 'super@test.com', 'password' => 'password', 'role' => 'super-admin']);

        $this->assertFalse($this->rbac->canDeleteMember($admin, $superAdmin));
    }

    public function test_can_delete_member_cannot_delete_self(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password', 'role' => 'admin']);

        $this->assertFalse($this->rbac->canDeleteMember($admin, $admin));
    }

    public function test_is_videographer_restricted(): void
    {
        $videographer = User::create(['name' => 'Vid', 'email' => 'vid@test.com', 'password' => 'password', 'role' => 'videographer']);
        $editor = User::create(['name' => 'Editor', 'email' => 'editor@test.com', 'password' => 'password', 'role' => 'editor']);

        $this->assertTrue($this->rbac->isVideographerRestricted($videographer));
        $this->assertFalse($this->rbac->isVideographerRestricted($editor));
    }

    public function test_builtin_roles_constant(): void
    {
        $expected = ['super-admin', 'admin', 'manager', 'editor', 'videographer', 'designer', 'copywriter', 'social-media'];
        $this->assertEquals($expected, RbacService::BUILT_IN_ROLES);
    }
}
