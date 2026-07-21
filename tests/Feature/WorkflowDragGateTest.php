<?php

namespace Tests\Feature;

use App\Models\DataAccess;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Services\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowDragGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowStage::create(['key' => 'draft', 'name' => 'Draft', 'order' => 1, 'color' => '#6b7280']);
        WorkflowStage::create(['key' => 'review', 'name' => 'Review', 'order' => 2, 'color' => '#f59e0b']);
        WorkflowStage::create(['key' => 'published', 'name' => 'Published', 'order' => 3, 'color' => '#10b981']);
    }

    public function test_editor_without_can_move_workflow_has_restricted_access(): void
    {
        $editor = User::create([
            'name' => 'Editor',
            'email' => 'editor@test.com',
            'password' => bcrypt('password'),
            'role' => 'editor',
            'status' => 'active',
        ]);

        $rbac = new RbacService;

        // Editor without data access cannot move workflow
        $this->assertFalse($rbac->hasDataAccess('editor', 'canMoveWorkflow'));
        $this->assertFalse($rbac->hasDataAccess('editor', 'canEditWorkflow'));
    }

    public function test_super_admin_can_move_and_edit_workflow(): void
    {
        $rbac = new RbacService;

        // Super admin has all permissions
        $this->assertTrue($rbac->hasDataAccess('super-admin', 'canMoveWorkflow'));
        $this->assertTrue($rbac->hasDataAccess('super-admin', 'canEditWorkflow'));
    }

    public function test_editor_with_data_access_can_move_workflow(): void
    {
        DataAccess::create([
            'role' => 'editor',
            'permissions' => ['canMoveWorkflow' => true, 'canEditWorkflow' => true],
        ]);

        $rbac = new RbacService;

        $this->assertTrue($rbac->hasDataAccess('editor', 'canMoveWorkflow'));
        $this->assertTrue($rbac->hasDataAccess('editor', 'canEditWorkflow'));
    }
}
