<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinalTestSeederSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DatabaseSeeder::class]);
    }

    /** @test */
    public function it_creates_admin_and_superadmin_users(): void
    {
        $this->assertSame(2, User::count(), 'DatabaseSeeder should create exactly 2 staff users');

        $this->assertDatabaseHas('users', ['email' => 'superadmin@madhyam.com', 'role' => 'super-admin']);
        $this->assertDatabaseHas('users', ['email' => 'admin@madhyam.com', 'role' => 'admin']);
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
    public function admin_can_reach_tasks(): void
    {
        $admin = User::where('email', 'admin@madhyam.com')->firstOrFail();

        $this->actingAs($admin, 'web')->get('/tasks')->assertOk();
    }
}
