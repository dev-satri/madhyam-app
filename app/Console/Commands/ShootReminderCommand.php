<?php

namespace App\Console\Commands;

use App\Models\NotificationRule;
use App\Models\Workflow;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class ShootReminderCommand extends Command
{
    protected $signature = 'notifications:shoot-reminders';

    protected $description = 'Send reminders for upcoming shoot deadlines based on notification rules';

    public function handle(): int
    {
        $rule = NotificationRule::where('trigger', 'shoot-reminder')->where('active', true)->first();
        if (! $rule) {
            $this->info('No active shoot-reminder rule found.');

            return self::SUCCESS;
        }

        $days = $rule->days;
        $targetDate = now()->addDays($days);

        $shoots = Workflow::where('type', 'shoot')
            ->where('stage', '!=', 'published')
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<=', $targetDate)
            ->whereDate('deadline', '>=', now())
            ->with('assigneeUser')
            ->get();

        $count = 0;
        foreach ($shoots as $shoot) {
            $daysUntil = now()->diffInDays($shoot->deadline, false);
            $notificationService = app(NotificationService::class);
            $notificationService->sendNotification(
                text: "Shoot '{$shoot->title}' is due in {$daysUntil} day(s) ({$shoot->deadline->format('M d, Y')})",
                type: 'warning',
                link: route('workflow', absolute: false),
                forRole: $shoot->assigneeUser ? $shoot->assigneeUser->role : 'all',
            );
            $count++;
        }

        $this->info("Sent {$count} shoot reminder(s).");

        return self::SUCCESS;
    }
}
