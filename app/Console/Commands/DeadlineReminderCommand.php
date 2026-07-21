<?php

namespace App\Console\Commands;

use App\Models\NotificationRule;
use App\Models\Task;
use App\Models\Workflow;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class DeadlineReminderCommand extends Command
{
    protected $signature = 'notifications:deadline-reminders';

    protected $description = 'Send reminders for approaching task and workflow deadlines';

    public function handle(): int
    {
        $rule = NotificationRule::where('trigger', 'deadline-reminder')->where('active', true)->first();
        if (! $rule) {
            $this->info('No active deadline-reminder rule found.');

            return self::SUCCESS;
        }

        $days = $rule->days;
        $targetDate = now()->addDays($days);
        $count = 0;

        // Task deadlines
        $tasks = Task::whereNotNull('due_date')
            ->whereNotIn('status', ['completed'])
            ->whereDate('due_date', '<=', $targetDate)
            ->whereDate('due_date', '>=', now())
            ->with('assigneeUser')
            ->get();

        foreach ($tasks as $task) {
            $daysUntil = now()->diffInDays($task->due_date, false);
            app(NotificationService::class)->sendNotification(
                text: "Task '{$task->title}' is due in {$daysUntil} day(s) ({$task->due_date->format('M d, Y')})",
                type: 'warning',
                link: route('tasks', absolute: false),
                forRole: $task->assigneeUser ? $task->assigneeUser->role : 'all',
            );
            $count++;
        }

        // Workflow deadlines
        $workflows = Workflow::whereNotNull('deadline')
            ->where('stage', '!=', 'published')
            ->whereDate('deadline', '<=', $targetDate)
            ->whereDate('deadline', '>=', now())
            ->with('assigneeUser')
            ->get();

        foreach ($workflows as $workflow) {
            $daysUntil = now()->diffInDays($workflow->deadline, false);
            app(NotificationService::class)->sendNotification(
                text: "Workflow '{$workflow->title}' is due in {$daysUntil} day(s) ({$workflow->deadline->format('M d, Y')})",
                type: 'warning',
                link: route('workflow', absolute: false),
                forRole: $workflow->assigneeUser ? $workflow->assigneeUser->role : 'all',
            );
            $count++;
        }

        $this->info("Sent {$count} deadline reminder(s).");

        return self::SUCCESS;
    }
}
