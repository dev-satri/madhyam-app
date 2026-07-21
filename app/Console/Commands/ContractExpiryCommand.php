<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\NotificationRule;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class ContractExpiryCommand extends Command
{
    protected $signature = 'notifications:contract-expiry';

    protected $description = 'Send reminders for expiring client contracts';

    public function handle(): int
    {
        $rule = NotificationRule::where('trigger', 'contract-expiry')->where('active', true)->first();
        if (! $rule) {
            $this->info('No active contract-expiry rule found.');

            return self::SUCCESS;
        }

        $days = $rule->days;
        $targetDate = now()->addDays($days);

        $clients = Client::where('status', 'active')
            ->whereNotNull('contract_end')
            ->whereDate('contract_end', '<=', $targetDate)
            ->whereDate('contract_end', '>=', now())
            ->get();

        $count = 0;
        foreach ($clients as $client) {
            $daysUntil = now()->diffInDays($client->contract_end, false);
            app(NotificationService::class)->sendNotification(
                text: "Contract for '{$client->name}' expires in {$daysUntil} day(s) ({$client->contract_end->format('M d, Y')})",
                type: 'warning',
                link: route('clients', absolute: false),
                forRole: 'admin',
            );
            $count++;
        }

        $this->info("Sent {$count} contract expiry reminder(s).");

        return self::SUCCESS;
    }
}
