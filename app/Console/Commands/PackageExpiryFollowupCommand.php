<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class PackageExpiryFollowupCommand extends Command
{
    protected $signature = 'client:expiry-followup';

    protected $description = 'Create automated follow-up tasks for expired/expiring client contracts';

    public function handle(): int
    {
        $count = 0;

        // 1. Clients whose contracts expired in the last 3 days — create follow-up task
        $recentlyExpired = Client::where('status', 'active')
            ->whereNotNull('contract_end')
            ->whereDate('contract_end', '>=', now()->subDays(3))
            ->whereDate('contract_end', '<', now())
            ->get();

        foreach ($recentlyExpired as $client) {
            $exists = Task::where('title', "Follow up: {$client->name} contract expired")
                ->where('client_id', $client->id)
                ->whereDate('created_at', '>=', now()->subDays(7))
                ->exists();

            if ($exists) {
                continue;
            }

            Task::create([
                'title' => "Follow up: {$client->name} contract expired",
                'description' => "Contract for {$client->name} ({$client->package}) expired on {$client->contract_end->format('M d, Y')}. Monthly amount: NPR " . number_format($client->amount, 2) . '. Please follow up for renewal.',
                'client_id' => $client->id,
                'assignee' => [$this->getManagerId()],
                'priority' => 'high',
                'status' => 'todo',
                'due_date' => now()->addDays(3),
            ]);

            $count++;
        }

        // 2. Clients expiring within 7 days — create renewal reminder task
        $expiringSoon = Client::where('status', 'active')
            ->whereNotNull('contract_end')
            ->whereDate('contract_end', '>', now())
            ->whereDate('contract_end', '<=', now()->addDays(7))
            ->get();

        foreach ($expiringSoon as $client) {
            $daysLeft = (int) now()->diffInDays($client->contract_end);

            $exists = Task::where('title', "Renewal reminder: {$client->name} ({$daysLeft}d)")
                ->where('client_id', $client->id)
                ->whereDate('created_at', '>=', now()->subDays(3))
                ->exists();

            if ($exists) {
                continue;
            }

            Task::create([
                'title' => "Renewal reminder: {$client->name} ({$daysLeft}d)",
                'description' => "{$client->name}'s contract expires in {$daysLeft} day(s) on {$client->contract_end->format('M d, Y')}. Package: {$client->package} — NPR " . number_format($client->amount, 2) . '/mo. Initiate renewal discussion.',
                'client_id' => $client->id,
                'assignee' => [$this->getManagerId()],
                'priority' => $daysLeft <= 3 ? 'high' : 'medium',
                'status' => 'todo',
                'due_date' => $client->contract_end,
            ]);

            $count++;
        }

        // 3. Escalation: clients expired 7+ days ago still active — escalate
        $escalationClients = Client::where('status', 'active')
            ->whereNotNull('contract_end')
            ->whereDate('contract_end', '<', now()->subDays(7))
            ->get();

        foreach ($escalationClients as $client) {
            $daysPast = (int) now()->diffInDays($client->contract_end);

            $notificationService = app(NotificationService::class);
            $notificationService->sendNotification(
                text: "ESCALATION: {$client->name}'s contract expired {$daysPast} days ago. Immediate action required.",
                type: 'error',
                link: route('clients', absolute: false),
                forRole: 'manager',
            );
        }

        $this->info("Created {$count} follow-up task(s).");

        return self::SUCCESS;
    }

    private function getManagerId(): ?int
    {
        $manager = User::where('role', 'manager')->first();

        return $manager?->id;
    }
}
