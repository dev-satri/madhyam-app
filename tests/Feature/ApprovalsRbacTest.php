<?php

namespace Tests\Feature;

use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers todo.md §5.2: Approvals — client cannot reject, staff can.
 */
class ApprovalsRbacTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected ClientAccount $client;

    protected int $approvalId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DatabaseSeeder::class]);

        $this->admin = User::where('role', 'super-admin')->first();
        $this->staff = User::where('role', 'editor')->first();
        $this->client = ClientAccount::first();
        $this->approvalId = DB::table('approvals')->first()->id ?? DB::table('approvals')->insertGetId([
            'title' => 'Test Approval', 'client_id' => 1, 'type' => 'post',
            'status' => 'pending', 'submitted_by' => $this->staff->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_staff_can_approve(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages.approvals.index')
            ->call('updateStatus', $this->approvalId, 'approved');

        $this->assertEquals('approved', DB::table('approvals')->where('id', $this->approvalId)->value('status'));
    }

    public function test_staff_can_reject(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages.approvals.index')
            ->call('updateStatus', $this->approvalId, 'rejected');

        $this->assertEquals('rejected', DB::table('approvals')->where('id', $this->approvalId)->value('status'));
    }

    public function test_staff_can_request_revision(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages.approvals.index')
            ->call('updateStatus', $this->approvalId, 'revision');

        $this->assertEquals('revision', DB::table('approvals')->where('id', $this->approvalId)->value('status'));
    }

    public function test_client_cannot_reject(): void
    {
        $this->actingAs($this->client, 'client');

        Livewire::test('pages.approvals.index')
            ->call('updateStatus', $this->approvalId, 'rejected');

        // Status should remain unchanged
        $this->assertNotEquals('rejected', DB::table('approvals')->where('id', $this->approvalId)->value('status'));
    }

    public function test_client_can_approve(): void
    {
        $this->actingAs($this->client, 'client');

        Livewire::test('pages.approvals.index')
            ->call('updateStatus', $this->approvalId, 'approved');

        $this->assertEquals('approved', DB::table('approvals')->where('id', $this->approvalId)->value('status'));
    }

    public function test_client_can_request_revision(): void
    {
        $this->actingAs($this->client, 'client');

        Livewire::test('pages.approvals.index')
            ->call('updateStatus', $this->approvalId, 'revision');

        $this->assertEquals('revision', DB::table('approvals')->where('id', $this->approvalId)->value('status'));
    }

    public function test_status_change_creates_system_comment(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('pages.approvals.index')
            ->call('updateStatus', $this->approvalId, 'approved');

        $this->assertDatabaseHas('approval_comments', [
            'approval_id' => $this->approvalId,
            'is_system' => true,
        ]);
    }

    public function test_bulk_approve(): void
    {
        $this->actingAs($this->admin);

        // Create additional pending approvals
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = DB::table('approvals')->insertGetId([
                'title' => "Bulk Approval {$i}", 'client_id' => 1, 'type' => 'post',
                'status' => 'pending', 'submitted_by' => $this->staff->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Livewire::test('pages.approvals.index')
            ->set('selectedItems', $ids)
            ->call('bulkApprove');

        foreach ($ids as $id) {
            $this->assertEquals('approved', DB::table('approvals')->where('id', $id)->value('status'));
        }
    }
}
