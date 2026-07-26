<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\NotificationRule;
use App\Notifications\PackageUsageLimitReachedNotification;
use App\Notifications\PackageUsageWarningNotification;
use App\Services\NotificationService;
use App\Services\PackageService;
use Illuminate\Console\Command;

class PackageUsageCheckCommand extends Command
{
    protected $signature = 'notifications:package-usage';

    protected $description = 'Check package usage for all active clients and send alerts at 80%/100% thresholds';

    public function handle(): int
    {
        $rule = NotificationRule::where('trigger', 'package-usage')->where('active', true)->first();
        if (! $rule) {
            $this->info('No active package-usage rule found.');

            return self::SUCCESS;
        }

        $clients = Client::where('status', 'active')
            ->whereNotNull('email')
            ->with('accounts')
            ->get();

        $count = 0;

        foreach ($clients as $client) {
            $count += $this->checkClient($client);
        }

        $this->info("Sent {$count} package usage notification(s).");

        return self::SUCCESS;
    }

    protected function checkClient(Client $client): int
    {
        $status = PackageService::getUsageWithStatus($client->id);
        $count = 0;

        if (empty($status['limits'])) {
            return 0;
        }

        $categories = [
            'content' => [
                'used' => $status['usage']['content_created'] ?? 0,
                'limit' => $status['limits']['content_limit'],
                'percent' => $status['content_pct'] ?? 0,
            ],
            'workflow' => [
                'used' => $status['usage']['workflow_items'] ?? 0,
                'limit' => $status['limits']['workflow_limit'],
                'percent' => $status['workflow_pct'] ?? 0,
            ],
            'storage' => [
                'used' => (int) round(($status['usage']['storage_used_bytes'] ?? 0) / 1048576),
                'limit' => $status['limits']['storage_limit_mb'],
                'percent' => $status['storage_pct'] ?? 0,
            ],
        ];

        foreach ($categories as $category => $data) {
            if ($data['percent'] >= 100) {
                $this->sendLimitNotification($client, $category, $data);
                $count++;
            } elseif ($data['percent'] >= 80) {
                $this->sendWarningNotification($client, $category, $data);
                $count++;
            }
        }

        return $count;
    }

    protected function sendWarningNotification(Client $client, string $category, array $data): void
    {
        // Mail to client
        $client->accounts->each(function ($account) use ($client, $category, $data) {
            $account->notify(new PackageUsageWarningNotification($client, array_merge($data, ['category' => $category])));
        });

        // In-app for client
        $categoryLabel = ucfirst($category);
        app(NotificationService::class)->sendNotification(
            text: "{$categoryLabel} usage at {$data['percent']}% ({$data['used']}/{$data['limit']}). Consider upgrading your package.",
            type: 'warning',
            link: route('client.dashboard', absolute: false),
            forRole: 'client',
            clientId: $client->id,
        );
    }

    protected function sendLimitNotification(Client $client, string $category, array $data): void
    {
        // Mail to client
        $client->accounts->each(function ($account) use ($client, $category, $data) {
            $account->notify(new PackageUsageLimitReachedNotification($client, array_merge($data, ['category' => $category])));
        });

        // In-app for client
        $categoryLabel = ucfirst($category);
        app(NotificationService::class)->sendNotification(
            text: "{$categoryLabel} limit reached! You've used {$data['used']}/{$data['limit']}. Upgrade your package to continue.",
            type: 'error',
            link: route('client.dashboard', absolute: false),
            forRole: 'client',
            clientId: $client->id,
        );
    }
}
