<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPortalIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected ClientAccount $clientAccount;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['name' => 'Test Client', 'email' => 'client@test.com', 'status' => 'active']);
        $this->clientAccount = ClientAccount::create([
            'client_id' => $client->id,
            'name' => 'Ram',
            'email' => 'ram@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $this->staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@test.com',
            'password' => bcrypt('password'),
            'role' => 'editor',
            'status' => 'active',
        ]);
    }

    public function test_client_cannot_use_staff_guard_login(): void
    {
        $this->post('/login', [
            'email' => 'ram@test.com',
            'password' => 'password',
        ]);

        $this->assertGuest('web');
    }

    public function test_staff_cannot_use_client_guard_login(): void
    {
        $this->post('/client/login', [
            'email' => 'staff@test.com',
            'password' => 'password',
        ]);

        $this->assertGuest('client');
    }

    public function test_client_account_exists_and_is_valid(): void
    {
        $this->assertDatabaseHas('client_accounts', [
            'email' => 'ram@test.com',
            'status' => 'active',
        ]);
    }

    public function test_staff_user_exists_and_is_valid(): void
    {
        $this->assertDatabaseHas('users', [
            'email' => 'staff@test.com',
            'role' => 'editor',
        ]);
    }

    public function test_client_cannot_authenticate_as_staff(): void
    {
        $this->post('/login', [
            'email' => 'ram@test.com',
            'password' => 'client123',
        ]);

        $this->assertGuest('web');
    }

    public function test_staff_cannot_authenticate_as_client(): void
    {
        $this->post('/client/login', [
            'email' => 'staff@test.com',
            'password' => 'password',
        ]);

        $this->assertGuest('client');
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
