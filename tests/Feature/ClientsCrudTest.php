<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PackageSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers todo.md §5.2: Clients CRUD + status cycling.
 */
class ClientsCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingsSeeder::class, PackageSeeder::class, DepartmentSeeder::class]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@clients.test',
            'password' => bcrypt('password'), 'role' => 'super-admin', 'status' => 'active',
        ]);
        $this->actingAs($this->admin);
    }

    public function test_clients_page_renders(): void
    {
        Livewire::test('pages.clients.index')
            ->assertStatus(200);
    }

    public function test_create_client(): void
    {
        Livewire::test('pages.clients.index')
            ->call('create')
            ->set('name', 'Test Corp')
            ->set('email', 'test@corp.com')
            ->set('package', 'basic')
            ->set('amount', 5000)
            ->set('status', 'active')
            ->call('save');

        $this->assertDatabaseHas('clients', [
            'name' => 'Test Corp',
            'email' => 'test@corp.com',
            'package' => 'basic',
            'amount' => 5000,
            'status' => 'active',
        ]);
    }

    public function test_edit_client(): void
    {
        $client = Client::create([
            'name' => 'Old Name', 'package' => 'basic', 'amount' => 1000, 'status' => 'active',
        ]);

        Livewire::test('pages.clients.index')
            ->call('edit', $client->id)
            ->set('name', 'New Name')
            ->set('amount', 9999)
            ->call('save');

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'New Name', 'amount' => 9999]);
    }

    public function test_delete_client(): void
    {
        $client = Client::create([
            'name' => 'Delete Me', 'package' => 'basic', 'amount' => 0, 'status' => 'active',
        ]);

        Livewire::test('pages.clients.index')
            ->call('delete', $client->id)
            ->call('performDelete');

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_validation_requires_name(): void
    {
        Livewire::test('pages.clients.index')
            ->call('create')
            ->set('name', '')
            ->set('package', 'basic')
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_search_filters_clients(): void
    {
        Client::create(['name' => 'Alpha Corp', 'package' => 'basic', 'amount' => 0, 'status' => 'active']);
        Client::create(['name' => 'Beta Inc', 'package' => 'pro', 'amount' => 0, 'status' => 'active']);

        Livewire::test('pages.clients.index')
            ->set('search', 'Alpha')
            ->assertSee('Alpha Corp')
            ->assertDontSee('Beta Inc');
    }

    public function test_status_filter(): void
    {
        Client::create(['name' => 'Active One', 'package' => 'basic', 'amount' => 0, 'status' => 'active']);
        Client::create(['name' => 'Inactive One', 'package' => 'basic', 'amount' => 0, 'status' => 'inactive']);

        Livewire::test('pages.clients.index')
            ->set('statusFilter', 'active')
            ->assertSee('Active One')
            ->assertDontSee('Inactive One');
    }
}
