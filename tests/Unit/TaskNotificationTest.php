<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Comment;
use App\Models\Content;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\ContentCommentNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCommentNotification;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskDeadlineNotification;
use App\Notifications\TaskOverdueNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $assignee;

    protected User $actor;

    protected Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assignee = User::factory()->create(['role' => 'editor']);
        $this->actor = User::factory()->create(['role' => 'manager']);

        $this->task = Task::create([
            'title' => 'Design social post',
            'type' => 'task',
            'priority' => 'high',
            'status' => 'todo',
            'assignee' => $this->assignee->id,
            'due_date' => now()->addDay(),
        ]);
    }

    // ── TaskAssignedNotification ──────────────────────────────

    public function test_task_assigned_mail_contains_subject(): void
    {
        $notification = new TaskAssignedNotification($this->task, $this->actor);
        $mail = $notification->toMail($this->assignee);

        $this->assertStringContainsString('assigned', $mail->subject);
        $this->assertStringContainsString($this->task->title, $mail->subject);
    }

    public function test_task_assigned_mail_renders_markdown(): void
    {
        $notification = new TaskAssignedNotification($this->task, $this->actor);
        $mail = $notification->toMail($this->assignee);

        $rendered = (string) $mail->render();
        $this->assertStringContainsString($this->task->title, $rendered);
    }

    public function test_task_assigned_in_app_payload(): void
    {
        $notification = new TaskAssignedNotification($this->task, $this->actor);
        $payload = $notification->toInApp($this->assignee);

        $this->assertArrayHasKey('text', $payload);
        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('link', $payload);
        $this->assertEquals('info', $payload['type']);
        $this->assertStringContainsString($this->task->title, $payload['text']);
    }

    public function test_task_assigned_in_app_includes_due_date(): void
    {
        $notification = new TaskAssignedNotification($this->task, $this->actor);
        $payload = $notification->toInApp($this->assignee);

        $this->assertStringContainsString('due', $payload['text']);
    }

    public function test_task_assigned_in_app_omits_due_when_null(): void
    {
        $this->task->update(['due_date' => null]);
        $notification = new TaskAssignedNotification($this->task, $this->actor);
        $payload = $notification->toInApp($this->assignee);

        $this->assertStringNotContainsString('due', $payload['text']);
    }

    public function test_task_assigned_via_channels(): void
    {
        $notification = new TaskAssignedNotification($this->task, $this->actor);
        $channels = $notification->via($this->assignee);

        $this->assertContains('mail', $channels);
        $this->assertCount(2, $channels);
    }

    public function test_task_assigned_shoot_type_label(): void
    {
        $this->task->update(['type' => 'shoot']);
        $notification = new TaskAssignedNotification($this->task, $this->actor);
        $mail = $notification->toMail($this->assignee);

        $this->assertStringContainsString('Shoot', $mail->subject);
    }

    public function test_task_assigned_editing_type_label(): void
    {
        $this->task->update(['type' => 'editing']);
        $notification = new TaskAssignedNotification($this->task, $this->actor);
        $mail = $notification->toMail($this->assignee);

        $this->assertStringContainsString('Editing task', $mail->subject);
    }

    // ── TaskCommentNotification ──────────────────────────────

    public function test_task_comment_mail_contains_subject(): void
    {
        $comment = TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->actor->id,
            'text' => 'Looks great!',
        ]);

        $notification = new TaskCommentNotification($comment, $this->actor);
        $mail = $notification->toMail($this->assignee);

        $this->assertStringContainsString('comment', strtolower($mail->subject));
        $this->assertStringContainsString($this->task->title, $mail->subject);
    }

    public function test_task_comment_in_app_payload(): void
    {
        $comment = TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->actor->id,
            'text' => 'Needs revision',
        ]);

        $notification = new TaskCommentNotification($comment, $this->actor);
        $payload = $notification->toInApp($this->assignee);

        $this->assertArrayHasKey('text', $payload);
        $this->assertEquals('info', $payload['type']);
        $this->assertStringContainsString('commented on', $payload['text']);
        $this->assertStringContainsString($this->task->title, $payload['text']);
        $this->assertStringContainsString('Needs revision', $payload['text']);
    }

    public function test_task_comment_in_app_truncates_long_comment(): void
    {
        $longText = str_repeat('a', 200);
        $comment = TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->actor->id,
            'text' => $longText,
        ]);

        $notification = new TaskCommentNotification($comment, $this->actor);
        $payload = $notification->toInApp($this->assignee);

        $this->assertLessThan(200, strlen($payload['text']));
    }

    public function test_task_comment_uses_actor_name(): void
    {
        $comment = TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->actor->id,
            'text' => 'Nice work',
        ]);

        $notification = new TaskCommentNotification($comment, $this->actor);
        $payload = $notification->toInApp($this->assignee);

        $this->assertStringContainsString($this->actor->name, $payload['text']);
    }

    public function test_task_comment_falls_back_to_someone_when_no_actor(): void
    {
        $comment = TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->actor->id,
            'text' => 'Nice work',
        ]);

        $notification = new TaskCommentNotification($comment, null);
        $payload = $notification->toInApp($this->assignee);

        $this->assertStringContainsString('Someone', $payload['text']);
    }

    // ── TaskCompletedNotification ──────────────────────────────

    public function test_task_completed_mail_contains_subject(): void
    {
        $notification = new TaskCompletedNotification($this->task, $this->actor);
        $mail = $notification->toMail($this->assignee);

        $this->assertStringContainsString('Completed', $mail->subject);
        $this->assertStringContainsString($this->task->title, $mail->subject);
    }

    public function test_task_completed_in_app_payload(): void
    {
        $notification = new TaskCompletedNotification($this->task, $this->actor);
        $payload = $notification->toInApp($this->assignee);

        $this->assertEquals('success', $payload['type']);
        $this->assertStringContainsString('completed', $payload['text']);
        $this->assertStringContainsString($this->task->title, $payload['text']);
    }

    public function test_task_completed_uses_actor_name(): void
    {
        $notification = new TaskCompletedNotification($this->task, $this->actor);
        $payload = $notification->toInApp($this->assignee);

        $this->assertStringContainsString($this->actor->name, $payload['text']);
    }

    public function test_task_completed_falls_back_to_team_member(): void
    {
        $notification = new TaskCompletedNotification($this->task, null);
        $payload = $notification->toInApp($this->assignee);

        $this->assertStringContainsString('A team member', $payload['text']);
    }

    // ── TaskDeadlineNotification ──────────────────────────────

    public function test_task_deadline_mail_today(): void
    {
        $notification = new TaskDeadlineNotification($this->task, 'today');
        $mail = $notification->toMail($this->assignee);

        $this->assertStringContainsString('today', strtolower($mail->subject));
        $this->assertStringContainsString($this->task->title, $mail->subject);
    }

    public function test_task_deadline_mail_tomorrow(): void
    {
        $notification = new TaskDeadlineNotification($this->task, 'tomorrow');
        $mail = $notification->toMail($this->assignee);

        $this->assertStringContainsString('tomorrow', strtolower($mail->subject));
    }

    public function test_task_deadline_in_app_today_is_warning(): void
    {
        $notification = new TaskDeadlineNotification($this->task, 'today');
        $payload = $notification->toInApp($this->assignee);

        $this->assertEquals('warning', $payload['type']);
        $this->assertStringContainsString('Due today', $payload['text']);
    }

    public function test_task_deadline_in_app_tomorrow_is_info(): void
    {
        $notification = new TaskDeadlineNotification($this->task, 'tomorrow');
        $payload = $notification->toInApp($this->assignee);

        $this->assertEquals('info', $payload['type']);
        $this->assertStringContainsString('Due tomorrow', $payload['text']);
    }

    public function test_task_deadline_in_app_includes_due_date(): void
    {
        $notification = new TaskDeadlineNotification($this->task, 'tomorrow');
        $payload = $notification->toInApp($this->assignee);

        $this->assertStringContainsString('due', $payload['text']);
    }

    // ── TaskOverdueNotification ──────────────────────────────

    public function test_task_overdue_mail_subject(): void
    {
        $notification = new TaskOverdueNotification($this->task, 3);
        $mail = $notification->toMail($this->assignee);

        $this->assertStringContainsString('Overdue', $mail->subject);
        $this->assertStringContainsString($this->task->title, $mail->subject);
    }

    public function test_task_overdue_in_app_payload(): void
    {
        $notification = new TaskOverdueNotification($this->task, 2);
        $payload = $notification->toInApp($this->assignee);

        $this->assertEquals('error', $payload['type']);
        $this->assertStringContainsString('Overdue by 2 days', $payload['text']);
        $this->assertStringContainsString($this->task->title, $payload['text']);
    }

    public function test_task_overdue_singular_day(): void
    {
        $notification = new TaskOverdueNotification($this->task, 1);
        $payload = $notification->toInApp($this->assignee);

        $this->assertStringContainsString('1 day', $payload['text']);
        $this->assertStringNotContainsString('1 days', $payload['text']);
    }

    public function test_task_overdue_plural_days(): void
    {
        $notification = new TaskOverdueNotification($this->task, 5);
        $payload = $notification->toInApp($this->assignee);

        $this->assertStringContainsString('5 days', $payload['text']);
    }

    // ── SkipsSelfActor ──────────────────────────────────────

    public function test_assigned_notification_skips_self_actor(): void
    {
        $notification = new TaskAssignedNotification($this->task, $this->assignee);

        $this->assertFalse($notification->shouldSend($this->assignee, 'mail'));
    }

    public function test_assigned_notification_sends_to_other_user(): void
    {
        $other = User::factory()->create(['role' => 'editor']);
        $notification = new TaskAssignedNotification($this->task, $this->actor);

        $this->assertTrue($notification->shouldSend($other, 'mail'));
    }

    public function test_assigned_notification_sends_when_no_actor(): void
    {
        $notification = new TaskAssignedNotification($this->task, null);

        $this->assertTrue($notification->shouldSend($this->assignee, 'mail'));
    }

    public function test_comment_notification_skips_self_actor(): void
    {
        $comment = TaskComment::create([
            'task_id' => $this->task->id,
            'user_id' => $this->assignee->id,
            'text' => 'Self comment',
        ]);

        $notification = new TaskCommentNotification($comment, $this->assignee);

        $this->assertFalse($notification->shouldSend($this->assignee, 'mail'));
    }

    public function test_skips_self_actor_matching_email(): void
    {
        $client = Client::create([
            'name' => 'Test Client',
            'email' => 'shared@example.com',
            'status' => 'active',
        ]);

        $clientAccount = ClientAccount::create([
            'client_id' => $client->id,
            'email' => 'shared@example.com',
            'name' => 'Shared User',
            'password' => bcrypt('password'),
        ]);

        $user = User::factory()->create([
            'email' => 'SHARED@example.com',
        ]);

        $content = Content::create([
            'title' => 'Test Content',
            'client_id' => $client->id,
            'status' => 'draft',
            'date' => now()->toDateString(),
        ]);

        $comment = Comment::create([
            'commentable_type' => Content::class,
            'commentable_id' => $content->id,
            'user_id' => $clientAccount->id,
            'user_type' => ClientAccount::class,
            'body' => 'Test comment',
        ]);

        $notification = new ContentCommentNotification($comment, $content, $clientAccount);

        $this->assertFalse($notification->shouldSend($user, 'mail'));
    }

    // ── Queuing ──────────────────────────────────────────────

    public function test_all_notifications_implement_should_queue(): void
    {
        $task = $this->task;
        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $this->actor->id,
            'text' => 'test',
        ]);

        $notifications = [
            new TaskAssignedNotification($task, $this->actor),
            new TaskCommentNotification($comment, $this->actor),
            new TaskCompletedNotification($task, $this->actor),
            new TaskDeadlineNotification($task),
            new TaskOverdueNotification($task),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
        }
    }
}
