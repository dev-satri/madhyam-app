<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\NotificationRule;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class ContractExpiryCommand extends Command
{
    protected $signature = 'notifications:contract-expiry';

    protected $description = 'Send reminders for expiring client contracts and packages';

    public function handle(): int
    {
        $rule = NotificationRule::where('trigger', 'contract-expiry')->where('active', true)->first();
        if (! $rule) {
            $this->info('No active contract-expiry rule found.');

            return self::SUCCESS;
        }

        $days = $rule->days;
        $targetDate = now()->addDays($days);
        $count = 0;

        // Clients with contracts expiring within N days
        $expiringClients = Client::where('status', 'active')
            ->whereNotNull('contract_end')
            ->whereDate('contract_end', '<=', $targetDate)
            ->whereDate('contract_end', '>=', now())
            ->get();

        foreach ($expiringClients as $client) {
            $daysUntil = max(0, (int) now()->diffInDays($client->contract_end, false));

            $urgency = match (true) {
                $daysUntil <= 3 => 'critical',
                $daysUntil <= 7 => 'high',
                $daysUntil <= 14 => 'medium',
                default => 'low',
            };

            $type = match ($urgency) {
                'critical' => 'error',
                'high' => 'warning',
                default => 'info',
            };

            $notificationService = app(NotificationService::class);
            $notificationService->sendContractExpiryNotification(
                client: $client,
                daysUntil: $daysUntil,
                type: $type,
            );
            $count++;
        }

        // Already expired clients (contract_end is in the past, still active)
        $expiredClients = Client::where('status', 'active')
            ->whereNotNull('contract_end')
            ->whereDate('contract_end', '<', now())
            ->get();

        foreach ($expiredClients as $client) {
            $daysPast = (int) now()->diffInDays($client->contract_end);
            $notificationService = app(NotificationService::class);
            $notificationService->sendContractExpiredNotification(
                client: $client,
                daysPast: $daysPast,
            );
            $count++;
        }

        $this->info("Sent {$count} contract expiry notification(s).");

        return self::SUCCESS;
    }
}
