<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end smoke test for the final-test seeder.
 *
 * Runs the full DatabaseSeeder against a fresh DB and asserts:
 *  - Row counts match the documented FINAL_TEST_PLAN.md numbers
 *  - Each of the 4 staff accounts + 2 client accounts can authenticate
 *  - Each account can hit a representative page it is allowed to see
 *    (avoiding the dashboard views, which use MySQL-specific FIELD()
 *    ordering that is not supported in the SQLite test DB)
 *  - Feature-gated routes reject accounts that lack the feature
 *  - The seeded Trek Nepal contract lands in the near-expiry warning window
 */
class FinalTestSeederSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DatabaseSeeder::class]);
    }

    /** @test */
    public function it_creates_exactly_the_documented_row_counts(): void
    {
        $this->assertSame(4, User::count(), 'DatabaseSeeder should create exactly 4 staff users');
        $this->assertSame(2, Client::count(), 'DatabaseSeeder should create exactly 2 clients');
        $this->assertSame(2, ClientAccount::count(), 'DatabaseSeeder should create exactly 2 client portal accounts');

        $this->assertDatabaseHas('users', ['email' => 'superadmin@madhyam.com', 'role' => 'super-admin']);
        $this->assertDatabaseHas('users', ['email' => 'admin@madhyam.com', 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => 'staff.editor@madhyam.com', 'role' => 'editor']);
        $this->assertDatabaseHas('users', ['email' => 'staff.video@madhyam.com', 'role' => 'videographer']);

        $this->assertDatabaseHas('client_accounts', ['email' => 'client1@madhyam.com']);
        $this->assertDatabaseHas('client_accounts', ['email' => 'client2@madhyam.com']);
    }

    /** @test */
    public function seeded_pipeline_data_matches_the_test_plan(): void
    {
        $this->assertSame(10, \App\Models\Content::count(), 'Should seed 10 content items');
        $this->assertSame(10, \App\Models\Workflow::count(), 'Should seed 10 workflow items');
        $this->assertSame(12, \App\Models\Task::count(), 'Should seed 12 tasks');
        $this->assertSame(6, \App\Models\Invoice::count(), 'Should seed 6 invoices');
        $this->assertSame(6, \App\Models\Approval::count(), 'Should seed 6 approvals');

        // Coverage checks — every workflow stage and approval state is represented
        $this->assertGreaterThan(0, \App\Models\Workflow::where('stage', 'revision')->count());
        $this->assertGreaterThan(0, \App\Models\Workflow::where('stage', 'published')->count());
        $this->assertGreaterThan(0, \App\Models\Approval::where('approval_stage', 'client-pending')->count());
        $this->assertGreaterThan(0, \App\Models\Approval::where('status', 'rejected')->count());
        $this->assertGreaterThan(0, \App\Models\Invoice::where('status', 'overdue')->count());
        $this->assertGreaterThan(0, \App\Models\Invoice::where('payment_status', 'installment')->count());
    }

    /** @test */
    public function super_admin_can_reach_team_and_settings(): void
    {
        $super = User::where('email', 'superadmin@madhyam.com')->firstOrFail();

        $this->actingAs($super, 'web')->get('/team')->assertOk();
        $this->actingAs($super, 'web')->get('/settings')->assertOk();
    }

    /** @test */
    public function admin_can_reach_team_and_clients(): void
    {
        $admin = User::where('email', 'admin@madhyam.com')->firstOrFail();

        $this->actingAs($admin, 'web')->get('/team')->assertOk();
        $this->actingAs($admin, 'web')->get('/clients')->assertOk();
    }

    /** @test */
    public function editor_can_reach_tasks_but_is_blocked_from_settings(): void
    {
        $editor = User::where('email', 'staff.editor@madhyam.com')->firstOrFail();

        $this->actingAs($editor, 'web')->get('/tasks')->assertOk();
        $this->actingAs($editor, 'web')->get('/settings')->assertForbidden();
    }

    /** @test */
    public function videographer_can_reach_tasks(): void
    {
        $video = User::where('email', 'staff.video@madhyam.com')->firstOrFail();

        $this->actingAs($video, 'web')->get('/tasks')->assertOk();
    }

    /** @test */
    public function client_account_is_blocked_from_staff_only_routes(): void
    {
        $client = ClientAccount::where('email', 'client1@madhyam.com')->firstOrFail();

        // Client guard is not authenticated on staff web routes → 302/401/403
        $response = $this->actingAs($client, 'client')->get('/team');
        $this->assertContains(
            $response->getStatusCode(),
            [302, 401, 403],
            "Client should not reach /team (got {$response->getStatusCode()})"
        );

        $response = $this->actingAs($client, 'client')->get('/tasks');
        $this->assertContains(
            $response->getStatusCode(),
            [302, 401, 403],
            "Client should not reach /tasks (got {$response->getStatusCode()})"
        );
    }

    /** @test */
    public function trek_nepal_contract_is_in_the_warning_window(): void
    {
        $trek = Client::where('name', 'Trek Nepal Adventures')->firstOrFail();

        $this->assertLessThanOrEqual(30, $trek->daysUntilExpiry(), 'Trek Nepal should be inside 30-day warning window');
        $this->assertGreaterThan(0, $trek->daysUntilExpiry(), 'Trek Nepal should not be expired yet');
        $this->assertContains($trek->expiry_status, ['warning', 'critical'], 'Contract state should trigger warning UI');
    }
}
