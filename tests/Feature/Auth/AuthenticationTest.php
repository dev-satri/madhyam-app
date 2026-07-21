<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => bcrypt('password'),
            'role' => 'editor',
            'status' => 'active',
        ]);

        $component = Volt::test('pages.auth.login')
            ->set('email', $user->email)
            ->set('password', 'password');

        $component->call('login');

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => bcrypt('password'),
            'role' => 'editor',
            'status' => 'active',
        ]);

        $component = Volt::test('pages.auth.login')
            ->set('email', $user->email)
            ->set('password', 'wrong-password');

        $component->call('login');

        $this->assertGuest();
    }
}
