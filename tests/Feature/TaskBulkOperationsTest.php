<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TaskBulkOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DatabaseSeeder::class]);

        $this->manager = User::where('role', 'admin')->first();
        $this->editor = User::where('role', 'super-admin')->first();
    }

    public function test_tasks_page_renders_for_manager(): void
    {
        $this->actingAs($this->manager);
        Livewire::test('pages.tasks.index')->assertStatus(200);
    }

    public function test_cycle_status_todo_to_in_progress(): void
    {
        $this->actingAs($this->manager);
        $taskId = DB::table('tasks')->insertGetId([
            'title' => 'Cycle Task', 'type' => 'task', 'priority' => 'medium',
            'assignee' => $this->editor->id, 'due_date' => now()->format('Y-m-d'),
            'status' => 'todo', 'progress' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.tasks.index')
            ->call('cycleStatus', $taskId);

        $this->assertEquals('in-progress', DB::table('tasks')->where('id', $taskId)->value('status'));
    }

    public function test_cycle_status_in_progress_to_completed(): void
    {
        $this->actingAs($this->manager);
        $taskId = DB::table('tasks')->insertGetId([
            'title' => 'Cycle Task 2', 'type' => 'task', 'priority' => 'medium',
            'assignee' => $this->editor->id, 'due_date' => now()->format('Y-m-d'),
            'status' => 'in-progress', 'progress' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.tasks.index')
            ->call('cycleStatus', $taskId);

        $this->assertEquals('completed', DB::table('tasks')->where('id', $taskId)->value('status'));
    }

    public function test_delete_task(): void
    {
        $this->actingAs($this->manager);
        $taskId = DB::table('tasks')->insertGetId([
            'title' => 'Delete Task', 'type' => 'task', 'priority' => 'low',
            'assignee' => $this->editor->id, 'due_date' => now()->format('Y-m-d'),
            'status' => 'todo', 'progress' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.tasks.index')
            ->call('deleteTask', $taskId);

        $this->assertSoftDeleted('tasks', ['id' => $taskId]);
    }

    public function test_bulk_properties_exist(): void
    {
        $this->actingAs($this->manager);
        $component = Livewire::test('pages.tasks.index');

        $component->assertSet('selected', []);
        $component->assertSet('bulkStatus', '');
        $component->assertSet('bulkAssigneeId', 0);
    }

    public function test_editor_only_sees_own_tasks(): void
    {
        $editor = User::create([
            'name' => 'Test Editor',
            'email' => 'testeditor@example.com',
            'password' => bcrypt('password'),
            'role' => 'editor',
            'status' => 'active',
        ]);
        $this->actingAs($editor);

        DB::table('tasks')->insert([
            'title' => 'My Task', 'type' => 'task', 'priority' => 'medium',
            'assignee' => $editor->id, 'due_date' => now()->format('Y-m-d'),
            'status' => 'todo', 'progress' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $other = User::where('role', 'admin')->first();
        DB::table('tasks')->insert([
            'title' => 'Not My Task', 'type' => 'task', 'priority' => 'medium',
            'assignee' => $other->id, 'due_date' => now()->format('Y-m-d'),
            'status' => 'todo', 'progress' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test('pages.tasks.index')
            ->assertSee('My Task')
            ->assertDontSee('Not My Task');
    }
}
