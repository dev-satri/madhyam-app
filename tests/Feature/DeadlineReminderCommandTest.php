<?php

namespace Tests\Feature;

use App\Models\NotificationRule;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDeadlineNotification;
use App\Notifications\TaskOverdueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeadlineReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $assignee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignee = User::factory()->create(['role' => 'editor']);

        NotificationRule::create([
            'name' => 'Deadline Reminder',
            'trigger' => 'deadline-reminder',
            'days' => 2,
            'active' => true,
        ]);
    }

    public function test_sends_deadline_notification_for_task_due_tomorrow(): void
    {
        Notification::fake();

        $task = Task::create([
            'title' => 'Due tomorrow',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'todo',
            'assignee' => $this->assignee->id,
            'due_date' => now()->addDay(),
        ]);

        $this->artisan('notifications:deadline-reminders')
            ->assertSuccessful();

        Notification::assertSentTo(
            $this->assignee,
            TaskDeadlineNotification::class
        );
    }

    public function test_sends_deadline_notification_for_task_due_today(): void
    {
        Notification::fake();

        $task = Task::create([
            'title' => 'Due today',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'todo',
            'assignee' => $this->assignee->id,
            'due_date' => now(),
        ]);

        $this->artisan('notifications:deadline-reminders')
            ->assertSuccessful();

        Notification::assertSentTo(
            $this->assignee,
            TaskDeadlineNotification::class
        );
    }

    public function test_sends_overdue_notification(): void
    {
        Notification::fake();

        $task = Task::create([
            'title' => 'Overdue task',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'todo',
            'assignee' => $this->assignee->id,
            'due_date' => now()->subDays(3),
        ]);

        $this->artisan('notifications:deadline-reminders')
            ->assertSuccessful();

        Notification::assertSentTo(
            $this->assignee,
            TaskOverdueNotification::class
        );
    }

    public function test_skips_completed_tasks(): void
    {
        Notification::fake();

        Task::create([
            'title' => 'Completed task',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'completed',
            'assignee' => $this->assignee->id,
            'due_date' => now()->addDay(),
        ]);

        $this->artisan('notifications:deadline-reminders')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_skips_tasks_without_assignee(): void
    {
        Notification::fake();

        Task::create([
            'title' => 'Unassigned task',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'todo',
            'assignee' => null,
            'due_date' => now()->addDay(),
        ]);

        $this->artisan('notifications:deadline-reminders')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_skips_tasks_outside_rule_window(): void
    {
        Notification::fake();

        // Rule is 2 days, task is due in 5 days
        Task::create([
            'title' => 'Far future task',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'todo',
            'assignee' => $this->assignee->id,
            'due_date' => now()->addDays(5),
        ]);

        $this->artisan('notifications:deadline-reminders')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_sends_multiple_notifications_for_multiple_tasks(): void
    {
        Notification::fake();

        $task1 = Task::create([
            'title' => 'Task 1',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'todo',
            'assignee' => $this->assignee->id,
            'due_date' => now()->addDay(),
        ]);

        $task2 = Task::create([
            'title' => 'Task 2',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'todo',
            'assignee' => $this->assignee->id,
            'due_date' => now(),
        ]);

        $this->artisan('notifications:deadline-reminders')
            ->assertSuccessful();

        Notification::assertSentTo(
            $this->assignee,
            TaskDeadlineNotification::class,
            2
        );
    }

    public function test_success_when_no_active_rule(): void
    {
        Notification::fake();

        NotificationRule::where('trigger', 'deadline-reminder')->update(['active' => false]);

        $this->artisan('notifications:deadline-reminders')
            ->expectsOutputToContain('No active deadline-reminder rule found.')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_success_when_no_rule_exists(): void
    {
        Notification::fake();

        NotificationRule::where('trigger', 'deadline-reminder')->delete();

        $this->artisan('notifications:deadline-reminders')
            ->expectsOutputToContain('No active deadline-reminder rule found.')
            ->assertSuccessful();
    }

    public function test_overdue_count_output(): void
    {
        Notification::fake();

        Task::create([
            'title' => 'Overdue 1',
            'type' => 'task',
            'priority' => 'medium',
            'status' => 'todo',
            'assignee' => $this->assignee->id,
            'due_date' => now()->subDay(),
        ]);

        $this->artisan('notifications:deadline-reminders')
            ->expectsOutputToContain('Sent')
            ->assertSuccessful();
    }
}
