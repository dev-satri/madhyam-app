<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use App\Support\ContentTags;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ContentDailyReminderCommand extends Command
{
    protected $signature = 'notifications:content-daily';

    protected $description = 'Send morning reminder for today\'s scheduled content';

    public function handle(): int
    {
        $today = now()->format('Y-m-d');
        $tomorrow = now()->addDay()->format('Y-m-d');

        $todayContent = DB::table('contents')
            ->join('clients', 'contents.client_id', '=', 'clients.id')
            ->whereDate('contents.date', $today)
            ->select('contents.*', 'clients.name as client_name')
            ->get();

        $tomorrowContent = DB::table('contents')
            ->join('clients', 'contents.client_id', '=', 'clients.id')
            ->whereDate('contents.date', $tomorrow)
            ->select('contents.*', 'clients.name as client_name')
            ->get();

        if ($todayContent->isEmpty() && $tomorrowContent->isEmpty()) {
            $this->info('No content scheduled for today or tomorrow.');

            return self::SUCCESS;
        }

        $svc = app(NotificationService::class);
        $count = 0;

        // Today's content summary
        if ($todayContent->isNotEmpty()) {
            $grouped = $todayContent->groupBy('client_name');
            $summary = $grouped->map(function ($items, $client) {
                $platforms = $items->pluck('platform')
                    ->map(fn ($p) => ContentTags::label(ContentTags::normalize($p, 'platform'), 'platform'))
                    ->implode(', ');
                $statuses = $items->pluck('status')
                    ->map(fn ($s) => str_replace('-', ' ', ucfirst($s)))
                    ->unique()->implode(', ');

                return "{$client}: " . $items->count() . " item(s) [{$platforms}] — {$statuses}";
            })->implode("\n  ");

            $total = $todayContent->count();
            $allPlatforms = $todayContent->pluck('platform')
                ->flatMap(fn ($p) => ContentTags::expand(ContentTags::normalize($p, 'platform'), 'platform'))
                ->unique()->values()->all();
            $platformLabel = ContentTags::label($allPlatforms, 'platform');

            $svc->sendNotification(
                text: "📅 Today's Content: {$total} item(s) scheduled across {$platformLabel}. Check the content planner for details.",
                type: 'info',
                link: route('content-planner', absolute: false),
                forRole: 'all',
            );
            $count++;
        }

        // Tomorrow's content preview
        if ($tomorrowContent->isNotEmpty()) {
            $total = $tomorrowContent->count();
            $allPlatforms = $tomorrowContent->pluck('platform')
                ->flatMap(fn ($p) => ContentTags::expand(ContentTags::normalize($p, 'platform'), 'platform'))
                ->unique()->values()->all();
            $platformLabel = ContentTags::label($allPlatforms, 'platform');

            $svc->sendNotification(
                text: "🔔 Tomorrow: {$total} content item(s) coming up across {$platformLabel}. Prepare captions and approvals.",
                type: 'warning',
                link: route('content-planner', absolute: false),
                forRole: 'all',
            );
            $count++;
        }

        // Per-client reminders for today
        $todayContent->groupBy('client_name')->each(function ($items, $clientName) use ($svc, &$count) {
            $platformList = $items->pluck('platform')
                ->map(fn ($p) => ContentTags::label(ContentTags::normalize($p, 'platform'), 'platform'))
                ->implode(', ');
            $svc->sendNotification(
                text: "Client \"{$clientName}\" has " . $items->count() . " content item(s) due today ({$platformList}).",
                type: 'info',
                link: route('content-planner', absolute: false),
                forRole: 'social-media',
            );
            $count++;
        });

        $this->info("Sent {$count} content reminder(s).");

        return self::SUCCESS;
    }
}
