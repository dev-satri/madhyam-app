<?php

namespace App\Console\Commands;

use App\Models\NotificationRule;
use App\Models\Task;
use App\Models\Workflow;
use App\Notifications\TaskDeadlineNotification;
use App\Notifications\TaskOverdueNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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

        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();

        // Task deadlines — today and tomorrow get per-assignee mail + in-app.
        // Anything further out than tomorrow but within the rule window still
        // goes through as a "tomorrow"-style heads-up so the rule.days knob
        // isn't silently ignored.
        $upcomingTasks = Task::whereNotNull('due_date')
            ->whereNotIn('status', ['completed'])
            ->whereDate('due_date', '<=', $targetDate)
            ->whereDate('due_date', '>=', $today)
            ->with('assigneeUser')
            ->get();

        foreach ($upcomingTasks as $task) {
            if (! $task->assigneeUser) {
                continue;
            }

            $when = $task->due_date->isSameDay($today) ? 'today' : 'tomorrow';
            $task->assigneeUser->notify(new TaskDeadlineNotification($task, $when));
            $count++;
        }

        // Overdue tasks — daily nag until the assignee closes them out.
        $overdueTasks = Task::whereNotNull('due_date')
            ->whereNotIn('status', ['completed'])
            ->whereDate('due_date', '<', $today)
            ->with('assigneeUser')
            ->get();

        foreach ($overdueTasks as $task) {
            if (! $task->assigneeUser) {
                continue;
            }

            // Carbon 3's diffInDays is signed — force absolute so a past date
            // reads as a positive integer (team memory: signed diff bug).
            $daysOverdue = (int) $today->diffInDays($task->due_date, absolute: true);
            if ($daysOverdue < 1) {
                $daysOverdue = 1;
            }

            $task->assigneeUser->notify(new TaskOverdueNotification($task, $daysOverdue));
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
