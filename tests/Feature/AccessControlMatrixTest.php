<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\DataAccessSeeder;
use Database\Seeders\FeatureAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks down the RBAC matrix seeded by FeatureAccessSeeder + DataAccessSeeder.
 * Any drift between the seeders and the expected map here will fail the tests.
 *
 * Covers todo.md §5.3:
 *   - Built-in roles × features matrix
 *   - Built-in roles × data permissions matrix
 *   - Client portal isolation (client cannot hit staff routes)
 *   - Super-admin self-protection (cannot delete self, cannot be deleted)
 */
class AccessControlMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Expected feature grants per built-in role.
     * Missing feature = expected FALSE for that role.
     */
    private const EXPECTED_FEATURES = [
        'super-admin' => [
            'dashboard', 'clients', 'packages', 'contentPlanner', 'workflow', 'tasks',
            'approvals', 'files', 'reports', 'leaves', 'expenses', 'salary', 'overtime',
            'team', 'settings', 'userGuide', 'complaints', 'clientPortal',
        ],
        'admin' => [
            'dashboard', 'clients', 'packages', 'contentPlanner', 'workflow', 'tasks',
            'approvals', 'files', 'reports', 'leaves', 'expenses', 'salary', 'overtime',
            'team', 'settings', 'userGuide', 'complaints', 'clientPortal',
        ],
        'manager' => [
            'dashboard', 'clients', 'packages', 'contentPlanner', 'workflow', 'tasks',
            'approvals', 'files', 'reports', 'leaves', 'expenses', 'salary', 'overtime',
            'team', 'userGuide', 'complaints', 'clientPortal',
        ],
        'editor' => ['dashboard', 'tasks', 'approvals', 'files'],
        'videographer' => ['dashboard', 'tasks', 'workflow', 'files'],
        'designer' => ['dashboard', 'tasks', 'files'],
        'copywriter' => ['dashboard', 'tasks', 'files'],
        'social-media' => ['dashboard', 'contentPlanner', 'tasks', 'files'],
    ];

    /**
     * Expected data permission grants per built-in role.
     */
    private const EXPECTED_PERMISSIONS = [
        'super-admin' => [
            'seeAllTasks', 'seeAllWorkflow', 'seeAllPerformance', 'seeAllActivity',
            'canAddTasks', 'canMoveWorkflow', 'canEditWorkflow',
        ],
        'admin' => [
            'seeAllTasks', 'seeAllWorkflow', 'seeAllPerformance', 'seeAllActivity',
            'canAddTasks', 'canMoveWorkflow', 'canEditWorkflow',
        ],
        'manager' => [
            'seeAllTasks', 'seeAllWorkflow', 'seeAllActivity',
            'canAddTasks', 'canMoveWorkflow', 'canEditWorkflow',
        ],
        'editor' => ['canMoveWorkflow'],
        'videographer' => ['canMoveWorkflow'],
        'designer' => ['canMoveWorkflow'],
        'copywriter' => ['canMoveWorkflow'],
        'social-media' => ['canMoveWorkflow'],
    ];

    /**
     * Staff-only routes the client portal must never reach.
     */
    private const STAFF_ROUTES = [
        '/dashboard', '/clients', '/packages', '/content-planner', '/workflow',
        '/tasks', '/approvals', '/files', '/reports', '/leaves', '/expenses',
        '/salary', '/overtime', '/team', '/settings', '/user-guide', '/complaints',
    ];

    protected RbacService $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FeatureAccessSeeder::class, DataAccessSeeder::class]);
        $this->rbac = new RbacService;
    }

    public function test_built_in_roles_cover_all_expected_roles(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(self::EXPECTED_FEATURES),
            RbacService::BUILT_IN_ROLES,
            'EXPECTED_FEATURES keys must mirror RbacService::BUILT_IN_ROLES'
        );
        $this->assertEqualsCanonicalizing(
            array_keys(self::EXPECTED_PERMISSIONS),
            RbacService::BUILT_IN_ROLES,
            'EXPECTED_PERMISSIONS keys must mirror RbacService::BUILT_IN_ROLES'
        );
    }

    public function test_feature_access_matrix_matches_seeder(): void
    {
        // 8 roles × 18 features = 144 assertions
        foreach (self::EXPECTED_FEATURES as $role => $grantedFeatures) {
            foreach (RbacService::FEATURES as $feature) {
                $expected = in_array($feature, $grantedFeatures, true);
                $actual = $this->rbac->hasFeature($role, $feature);
                $this->assertSame(
                    $expected,
                    $actual,
                    "hasFeature('{$role}', '{$feature}') expected ".($expected ? 'true' : 'false')
                );
            }
        }
    }

    public function test_data_access_matrix_matches_seeder(): void
    {
        // 8 roles × 7 permissions = 56 assertions
        foreach (self::EXPECTED_PERMISSIONS as $role => $grantedPerms) {
            foreach (RbacService::PERMISSIONS as $perm) {
                $expected = in_array($perm, $grantedPerms, true);
                $actual = $this->rbac->hasDataAccess($role, $perm);
                $this->assertSame(
                    $expected,
                    $actual,
                    "hasDataAccess('{$role}', '{$perm}') expected ".($expected ? 'true' : 'false')
                );
            }
        }
    }

    public function test_authenticated_client_cannot_reach_staff_routes(): void
    {
        $client = Client::create([
            'name' => 'Isolation Client',
            'email' => 'iso-client@test.com',
            'status' => 'active',
        ]);
        $account = ClientAccount::create([
            'client_id' => $client->id,
            'name' => 'ClientUser',
            'email' => 'iso-user@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $this->actingAs($account, 'client');

        foreach (self::STAFF_ROUTES as $route) {
            $response = $this->get($route);
            $this->assertContains(
                $response->status(),
                [302, 403],
                "Client should NOT reach staff route {$route} — got HTTP ".$response->status()
            );

            if ($response->status() === 302) {
                $location = $response->headers->get('Location', '');
                $this->assertStringContainsString(
                    'login',
                    $location,
                    "Redirect from staff route {$route} must land on login page (got {$location})"
                );
            }
        }
    }

    public function test_super_admin_cannot_be_deleted_by_anyone(): void
    {
        $super = User::create([
            'name' => 'Super', 'email' => 'super@matrix.test',
            'password' => bcrypt('x'), 'role' => 'super-admin',
        ]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@matrix.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $manager = User::create([
            'name' => 'Mgr', 'email' => 'mgr@matrix.test',
            'password' => bcrypt('x'), 'role' => 'manager',
        ]);

        // No actor can delete a super-admin, including another super-admin
        $this->assertFalse($this->rbac->canDeleteMember($super, $super), 'super cannot delete self');
        $this->assertFalse($this->rbac->canDeleteMember($admin, $super), 'admin cannot delete super');
        $this->assertFalse($this->rbac->canDeleteMember($manager, $super), 'manager cannot delete super');
    }

    public function test_super_admin_cannot_delete_self(): void
    {
        $super = User::create([
            'name' => 'Super', 'email' => 'super-self@matrix.test',
            'password' => bcrypt('x'), 'role' => 'super-admin',
        ]);
        $this->assertFalse($this->rbac->canDeleteMember($super, $super));
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-self@matrix.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $this->assertFalse($this->rbac->canDeleteMember($admin, $admin));
    }

    public function test_only_super_admin_can_edit_super_admin(): void
    {
        $super = User::create([
            'name' => 'Super', 'email' => 'super-edit@matrix.test',
            'password' => bcrypt('x'), 'role' => 'super-admin',
        ]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-edit@matrix.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $manager = User::create([
            'name' => 'Mgr', 'email' => 'mgr-edit@matrix.test',
            'password' => bcrypt('x'), 'role' => 'manager',
        ]);

        $this->assertFalse($this->rbac->canEditMember($admin, $super), 'admin cannot edit super');
        $this->assertFalse($this->rbac->canEditMember($manager, $super), 'manager cannot edit super');
        $this->assertTrue($this->rbac->canEditMember($super, $super), 'super can edit self');
    }
}
