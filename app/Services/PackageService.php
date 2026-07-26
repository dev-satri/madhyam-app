<?php

namespace App\Services;

use App\Models\Client;
use App\Notifications\PackageUsageLimitReachedNotification;
use App\Notifications\PackageUsageWarningNotification;
use Illuminate\Support\Facades\DB;

class PackageService
{
    public static function getUsage(int $clientId, ?int $month = null, ?int $year = null): array
    {
        $month = $month ?? (int) now()->format('m');
        $year = $year ?? (int) now()->format('Y');

        $usage = DB::table('package_usage')
            ->where('client_id', $clientId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if (! $usage) {
            $client = DB::table('clients')->where('id', $clientId)->first();
            $pkg = $client ? DB::table('packages')->where('slug', $client->package)->first() : null;

            $usage = (object) [
                'client_id' => $clientId,
                'package_id' => $pkg?->id,
                'month' => $month,
                'year' => $year,
                'content_created' => 0,
                'content_published' => 0,
                'workflow_items' => 0,
                'approvals_used' => 0,
                'files_uploaded' => 0,
                'storage_used_bytes' => 0,
            ];
        }

        return (array) $usage;
    }

    public static function getLimits(int $clientId): array
    {
        $client = DB::table('clients')->where('id', $clientId)->first();
        if (! $client) {
            return [];
        }

        $pkg = DB::table('packages')->where('slug', $client->package)->first();
        if (! $pkg) {
            return [];
        }

        return [
            'client_id' => $clientId,
            'package_name' => $pkg->name,
            'package_slug' => $pkg->slug,
            'monthly_amount' => (float) $pkg->monthly_amount,
            'content_limit' => (int) $pkg->content_limit,
            'workflow_limit' => (int) $pkg->workflow_limit,
            'storage_limit_mb' => (int) $pkg->storage_limit_mb,
            'revision_limit' => (int) $pkg->revision_limit,
            'priority_support' => (bool) $pkg->priority_support,
            'included_platforms' => json_decode($pkg->included_platforms ?? '[]', true),
            'features' => json_decode($pkg->features ?? '[]', true),
            'deliverables' => $pkg->deliverables,
        ];
    }

    public static function getUsageWithStatus(int $clientId, ?int $month = null, ?int $year = null): array
    {
        $usage = self::getUsage($clientId, $month, $year);
        $limits = self::getLimits($clientId);

        if (empty($limits)) {
            return ['usage' => $usage, 'limits' => [], 'alerts' => []];
        }

        $alerts = [];

        // Check content limit
        $contentPct = self::getUsagePercent($usage['content_created'] ?? 0, $limits['content_limit']);
        if ($contentPct >= 100) {
            $alerts[] = ['type' => 'danger', 'message' => 'Content limit reached! Upgrade your plan.'];
        } elseif ($contentPct >= 80) {
            $alerts[] = ['type' => 'warning', 'message' => 'Content usage at ' . $contentPct . '%. Consider upgrading.'];
        }

        // Check workflow limit
        $workflowPct = self::getUsagePercent($usage['workflow_items'] ?? 0, $limits['workflow_limit']);
        if ($workflowPct >= 100) {
            $alerts[] = ['type' => 'danger', 'message' => 'Workflow limit reached! Upgrade your plan.'];
        } elseif ($workflowPct >= 80) {
            $alerts[] = ['type' => 'warning', 'message' => 'Workflow usage at ' . $workflowPct . '%. Consider upgrading.'];
        }

        // Check storage limit
        $storageUsedMb = round(($usage['storage_used_bytes'] ?? 0) / 1048576, 1);
        $storagePct = self::getUsagePercent($storageUsedMb, $limits['storage_limit_mb']);
        if ($storagePct >= 100) {
            $alerts[] = ['type' => 'danger', 'message' => 'Storage limit reached! Upgrade your plan.'];
        } elseif ($storagePct >= 80) {
            $alerts[] = ['type' => 'warning', 'message' => 'Storage usage at ' . $storagePct . '%. Consider upgrading.'];
        }

        return [
            'usage' => $usage,
            'limits' => $limits,
            'alerts' => $alerts,
            'content_pct' => $contentPct,
            'workflow_pct' => $workflowPct,
            'storage_pct' => $storagePct,
        ];
    }

    public static function getUpgradeOptions(int $clientId): array
    {
        $client = DB::table('clients')->where('id', $clientId)->first();
        if (! $client) {
            return [];
        }

        $currentSlug = $client->package;
        $packages = DB::table('packages')
            ->where('status', 'active')
            ->orderBy('monthly_amount')
            ->get()
            ->toArray();

        $options = [];
        foreach ($packages as $pkg) {
            $options[] = [
                'id' => $pkg->id,
                'slug' => $pkg->slug,
                'name' => $pkg->name,
                'monthly_amount' => (float) $pkg->monthly_amount,
                'is_current' => $pkg->slug === $currentSlug,
                'is_upgrade' => $pkg->slug !== $currentSlug,
                'content_limit' => (int) $pkg->content_limit,
                'workflow_limit' => (int) $pkg->workflow_limit,
                'storage_limit_mb' => (int) $pkg->storage_limit_mb,
                'revision_limit' => (int) $pkg->revision_limit,
                'priority_support' => (bool) $pkg->priority_support,
                'included_platforms' => json_decode($pkg->included_platforms ?? '[]', true),
                'features' => json_decode($pkg->features ?? '[]', true),
                'deliverables' => $pkg->deliverables,
            ];
        }

        return $options;
    }

    public static function upgradePackage(int $clientId, string $newPackageSlug): bool
    {
        $client = DB::table('clients')->where('id', $clientId)->first();
        if (! $client) {
            return false;
        }

        $newPkg = DB::table('packages')->where('slug', $newPackageSlug)->where('status', 'active')->first();
        if (! $newPkg) {
            return false;
        }

        // Update client package
        DB::table('clients')->where('id', $clientId)->update([
            'package' => $newPackageSlug,
            'amount' => $newPkg->monthly_amount,
            'updated_at' => now(),
        ]);

        // Log activity
        DB::table('activity_logs')->insert([
            'user_id' => null,
            'user' => 'System',
            'text' => "Package upgraded from {$client->package} to {$newPackageSlug} for client: {$client->name}",
            'time' => now(),
            'created_at' => now(),
        ]);

        return true;
    }

    public static function getAllClientsUsage(): array
    {
        $clients = DB::table('clients')
            ->where('status', 'active')
            ->get();

        $result = [];
        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');

        foreach ($clients as $client) {
            $limits = self::getLimits($client->id);
            $usage = self::getUsage($client->id, $month, $year);

            if (empty($limits)) {
                continue;
            }

            $contentPct = self::getUsagePercent($usage['content_created'] ?? 0, $limits['content_limit']);
            $workflowPct = self::getUsagePercent($usage['workflow_items'] ?? 0, $limits['workflow_limit']);
            $storageUsedMb = round(($usage['storage_used_bytes'] ?? 0) / 1048576, 1);
            $storagePct = self::getUsagePercent($storageUsedMb, $limits['storage_limit_mb']);

            $maxPct = max($contentPct, $workflowPct, $storagePct);

            $result[] = [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'package_slug' => $limits['package_slug'],
                'package_name' => $limits['package_name'],
                'content_used' => $usage['content_created'] ?? 0,
                'content_limit' => $limits['content_limit'],
                'content_pct' => $contentPct,
                'workflow_used' => $usage['workflow_items'] ?? 0,
                'workflow_limit' => $limits['workflow_limit'],
                'workflow_pct' => $workflowPct,
                'storage_used' => $storageUsedMb,
                'storage_limit' => $limits['storage_limit_mb'],
                'storage_pct' => $storagePct,
                'approvals_used' => $usage['approvals_used'] ?? 0,
                'files_uploaded' => $usage['files_uploaded'] ?? 0,
                'max_pct' => $maxPct,
                'needs_attention' => $maxPct >= 80,
                'is_over_limit' => $maxPct >= 100,
            ];
        }

        // Sort by usage descending (highest usage first)
        usort($result, fn ($a, $b) => $b['max_pct'] <=> $a['max_pct']);

        return $result;
    }

    public static function recordContent(int $clientId, string $type = 'created'): void
    {
        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');
        $column = $type === 'published' ? 'content_published' : 'content_created';

        $usage = self::getUsage($clientId, $month, $year);

        DB::table('package_usage')->updateOrInsert(
            ['client_id' => $clientId, 'month' => $month, 'year' => $year],
            [
                $column => ($usage[$column] ?? 0) + 1,
                'updated_at' => now(),
            ]
        );

        self::fireUsageAlertIfNeeded($clientId, 'content');
    }

    public static function recordWorkflow(int $clientId): void
    {
        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');
        $usage = self::getUsage($clientId, $month, $year);

        DB::table('package_usage')->updateOrInsert(
            ['client_id' => $clientId, 'month' => $month, 'year' => $year],
            [
                'workflow_items' => ($usage['workflow_items'] ?? 0) + 1,
                'updated_at' => now(),
            ]
        );

        self::fireUsageAlertIfNeeded($clientId, 'workflow');
    }

    public static function recordFile(int $clientId, int $sizeBytes): void
    {
        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');
        $usage = self::getUsage($clientId, $month, $year);

        DB::table('package_usage')->updateOrInsert(
            ['client_id' => $clientId, 'month' => $month, 'year' => $year],
            [
                'files_uploaded' => ($usage['files_uploaded'] ?? 0) + 1,
                'storage_used_bytes' => ($usage['storage_used_bytes'] ?? 0) + $sizeBytes,
                'updated_at' => now(),
            ]
        );

        self::fireUsageAlertIfNeeded($clientId, 'storage');
    }

    public static function recordApproval(int $clientId): void
    {
        $month = (int) now()->format('m');
        $year = (int) now()->format('Y');
        $usage = self::getUsage($clientId, $month, $year);

        DB::table('package_usage')->updateOrInsert(
            ['client_id' => $clientId, 'month' => $month, 'year' => $year],
            [
                'approvals_used' => ($usage['approvals_used'] ?? 0) + 1,
                'updated_at' => now(),
            ]
        );
    }

    public static function getUsagePercent(int $used, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        return min(100, (int) round(($used / $limit) * 100));
    }

    protected static function fireUsageAlertIfNeeded(int $clientId, string $category): void
    {
        $status = self::getUsageWithStatus($clientId);

        if (empty($status['limits'])) {
            return;
        }

        $client = Client::with('accounts')->find($clientId);
        if (! $client) {
            return;
        }

        $percentMap = [
            'content' => $status['content_pct'] ?? 0,
            'workflow' => $status['workflow_pct'] ?? 0,
            'storage' => $status['storage_pct'] ?? 0,
        ];

        $percent = $percentMap[$category] ?? 0;

        $dataMap = [
            'content' => [
                'used' => $status['usage']['content_created'] ?? 0,
                'limit' => $status['limits']['content_limit'],
            ],
            'workflow' => [
                'used' => $status['usage']['workflow_items'] ?? 0,
                'limit' => $status['limits']['workflow_limit'],
            ],
            'storage' => [
                'used' => (int) round(($status['usage']['storage_used_bytes'] ?? 0) / 1048576),
                'limit' => $status['limits']['storage_limit_mb'],
            ],
        ];

        $data = $dataMap[$category];
        $categoryLabel = ucfirst($category);
        $notificationService = app(NotificationService::class);

        if ($percent >= 100) {
            $client->accounts->each(function ($account) use ($client, $category, $data, $percent) {
                $account->notify(new PackageUsageLimitReachedNotification($client, [
                    'category' => $category,
                    'used' => $data['used'],
                    'limit' => $data['limit'],
                    'percent' => $percent,
                ]));
            });

            $notificationService->sendNotification(
                text: "{$categoryLabel} limit reached! You've used {$data['used']}/{$data['limit']}. Upgrade your package to continue.",
                type: 'error',
                link: route('client.dashboard', absolute: false),
                forRole: 'client',
                clientId: $clientId,
            );
        } elseif ($percent >= 80) {
            $client->accounts->each(function ($account) use ($client, $category, $data, $percent) {
                $account->notify(new PackageUsageWarningNotification($client, [
                    'category' => $category,
                    'used' => $data['used'],
                    'limit' => $data['limit'],
                    'percent' => $percent,
                ]));
            });

            $notificationService->sendNotification(
                text: "{$categoryLabel} usage at {$percent}% ({$data['used']}/{$data['limit']}). Consider upgrading your package.",
                type: 'warning',
                link: route('client.dashboard', absolute: false),
                forRole: 'client',
                clientId: $clientId,
            );
        }
    }

    public static function isNearLimit(int $used, int $limit, float $threshold = 0.8): bool
    {
        if ($limit <= 0) {
            return false;
        }

        return ($used / $limit) >= $threshold;
    }

    public static function isOverLimit(int $used, int $limit): bool
    {
        if ($limit <= 0) {
            return false;
        }

        return $used > $limit;
    }

    public static function getPackageStats(): array
    {
        $packages = DB::table('packages')->where('status', 'active')->get();
        $stats = [];

        foreach ($packages as $pkg) {
            $clientCount = DB::table('clients')->where('package', $pkg->slug)->where('status', 'active')->count();
            $totalRevenue = DB::table('clients')
                ->where('package', $pkg->slug)
                ->where('status', 'active')
                ->sum('amount');

            $stats[] = [
                'id' => $pkg->id,
                'slug' => $pkg->slug,
                'name' => $pkg->name,
                'status' => $pkg->status,
                'monthly_amount' => (float) $pkg->monthly_amount,
                'client_count' => $clientCount,
                'total_revenue' => (float) $totalRevenue,
                'content_limit' => (int) $pkg->content_limit,
                'workflow_limit' => (int) $pkg->workflow_limit,
                'storage_limit_mb' => (int) $pkg->storage_limit_mb,
                'revision_limit' => (int) $pkg->revision_limit,
                'priority_support' => (bool) $pkg->priority_support,
                'included_platforms' => json_decode($pkg->included_platforms ?? '[]', true),
                'features' => json_decode($pkg->features ?? '[]', true),
                'deliverables' => $pkg->deliverables,
            ];
        }

        return $stats;
    }
}
