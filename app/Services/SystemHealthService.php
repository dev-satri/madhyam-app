<?php

namespace App\Services;

use Cron\CronExpression;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class SystemHealthService
{
    private const RUN_LEDGER = 'system-health/schedule-runs.json';

    private const RUNS_PER_COMMAND = 20;

    private const TZ = 'Asia/Kathmandu';

    private const ALLOWED_CACHES = [
        'application' => 'cache:clear',
        'view' => 'view:clear',
        'config' => 'config:clear',
        'route' => 'route:clear',
        'compiled' => 'clear-compiled',
        'event' => 'event:clear',
    ];

    /* ---------------------------------------------------------------
     |  Queue
     |--------------------------------------------------------------- */

    public function queueStats(): array
    {
        return [
            'pending' => DB::table('jobs')->count(),
            'reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
            'failed' => DB::table('failed_jobs')->count(),
            'oldest_pending_at' => optional(
                DB::table('jobs')->orderBy('created_at')->value('created_at')
            ) ? Carbon::createFromTimestamp(
                (int) DB::table('jobs')->orderBy('created_at')->value('created_at')
            )->timezone(self::TZ)->format('Y-m-d H:i:s') : null,
        ];
    }

    public function failedJobs(int $limit = 25): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $payload = json_decode($row->payload, true);
                $display = $payload['displayName']
                    ?? ($payload['data']['commandName'] ?? 'Unknown job');

                return [
                    'uuid' => $row->uuid,
                    'connection' => $row->connection,
                    'queue' => $row->queue,
                    'display' => $display,
                    'failed_at' => Carbon::parse($row->failed_at)->timezone(self::TZ)->format('Y-m-d H:i:s'),
                    'exception_first_line' => trim(strtok((string) $row->exception, "\n")),
                    'exception' => (string) $row->exception,
                ];
            })
            ->all();
    }

    public function retryFailed(string $uuid): string
    {
        // queue:retry accepts one or more UUIDs (or "all").
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return trim(Artisan::output());
    }

    public function retryAllFailed(): string
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return trim(Artisan::output());
    }

    public function deleteFailed(string $uuid): string
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return trim(Artisan::output());
    }

    public function flushAllFailed(): string
    {
        Artisan::call('queue:flush');

        return trim(Artisan::output());
    }

    /* ---------------------------------------------------------------
     |  Scheduler
     |--------------------------------------------------------------- */

    public function scheduledCommands(): array
    {
        $schedule = app(Schedule::class);
        $history = $this->loadRunLedger();
        $now = Carbon::now(self::TZ);
        $out = [];

        foreach ($schedule->events() as $event) {
            $description = $event->description ?: $event->command;
            $key = $this->commandKey($event->command ?: $description);

            $last = $history[$key] ?? [];
            $lastRun = $last[0] ?? null;

            try {
                $next = Carbon::parse(
                    (new CronExpression($event->expression))->getNextRunDate('now')
                )->timezone(self::TZ);
                $nextStr = $next->format('Y-m-d H:i');
                $nextRelative = $now->diffForHumans($next, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]);
            } catch (\Throwable $e) {
                $nextStr = '—';
                $nextRelative = '—';
            }

            $out[] = [
                'key' => $key,
                'command' => $event->command ?: $description,
                'description' => $description,
                'expression' => $event->expression,
                'next_run' => $nextStr,
                'next_in' => $nextRelative,
                'last_run_at' => $lastRun['ran_at'] ?? null,
                'last_exit_code' => $lastRun['exit_code'] ?? null,
                'last_duration_ms' => $lastRun['duration_ms'] ?? null,
            ];
        }

        // Stable sort — earliest next run first
        usort($out, fn ($a, $b) => strcmp($a['next_run'], $b['next_run']));

        return $out;
    }

    public function runScheduledCommand(string $commandKey): string
    {
        $schedule = app(Schedule::class);
        foreach ($schedule->events() as $event) {
            if ($this->commandKey($event->command ?: $event->description) === $commandKey) {
                // Extract the artisan sub-command from something like
                // "'/usr/bin/php7.4' 'artisan' notifications:payment-reminders"
                $sub = $this->extractArtisanSubcommand($event->command);
                if (! $sub) {
                    return 'Unable to parse artisan command for this scheduled task.';
                }
                $started = microtime(true);
                Artisan::call($sub);
                $duration = (int) ((microtime(true) - $started) * 1000);

                // Manually record — ScheduledTaskFinished only fires under `schedule:run`.
                $this->appendRun($this->commandKey($event->command ?: $event->description), [
                    'ran_at' => Carbon::now(self::TZ)->format('Y-m-d H:i:s'),
                    'exit_code' => 0,
                    'duration_ms' => $duration,
                    'triggered' => 'manual',
                ]);

                return trim(Artisan::output()) ?: 'Command finished with no output.';
            }
        }

        return 'Scheduled command not found.';
    }

    public function recordScheduledRun(ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        $key = $this->commandKey($task->command ?: $task->description);
        $this->appendRun($key, [
            'ran_at' => Carbon::now(self::TZ)->format('Y-m-d H:i:s'),
            'exit_code' => (int) ($task->exitCode ?? 0),
            'duration_ms' => (int) round($event->runtime * 1000),
            'triggered' => 'schedule',
        ]);
    }

    /* ---------------------------------------------------------------
     |  Caches
     |--------------------------------------------------------------- */

    public function clearCache(string $type): string
    {
        if (! isset(self::ALLOWED_CACHES[$type])) {
            return 'Unknown cache type.';
        }
        Artisan::call(self::ALLOWED_CACHES[$type]);

        return trim(Artisan::output()) ?: 'Cleared.';
    }

    public function clearAllCaches(): string
    {
        $lines = [];
        foreach (self::ALLOWED_CACHES as $type => $cmd) {
            Artisan::call($cmd);
            $lines[] = str_pad($type, 12) . ' — ' . (trim(Artisan::output()) ?: 'ok');
        }

        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------
     |  Mail test
     |--------------------------------------------------------------- */

    public function sendTestMail(string $to): array
    {
        try {
            Mail::raw(
                "This is a Madhyam System Health test message.\n\nSent at " . Carbon::now(self::TZ)->format('Y-m-d H:i:s T') . '.',
                function (Message $m) use ($to) {
                    $m->to($to)->subject('Madhyam — Mail delivery test');
                }
            );

            return ['ok' => true, 'message' => "Test mail dispatched to {$to}."];
        } catch (\Throwable $e) {
            Log::warning('System health test mail failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Failed: ' . $e->getMessage()];
        }
    }

    /* ---------------------------------------------------------------
     |  Internals
     |--------------------------------------------------------------- */

    private function commandKey(string $raw): string
    {
        $sub = $this->extractArtisanSubcommand($raw);

        return $sub ?: trim($raw);
    }

    private function extractArtisanSubcommand(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        // Scheduled artisan commands look like:  '/path/php' 'artisan' cmd:name
        if (preg_match("/['\"]?artisan['\"]?\s+([^'\"\s].*)$/", $raw, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function loadRunLedger(): array
    {
        if (! Storage::disk('local')->exists(self::RUN_LEDGER)) {
            return [];
        }
        $json = Storage::disk('local')->get(self::RUN_LEDGER);
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private function appendRun(string $key, array $entry): void
    {
        $data = $this->loadRunLedger();
        $data[$key] = array_slice(
            array_merge([$entry], $data[$key] ?? []),
            0,
            self::RUNS_PER_COMMAND
        );
        Storage::disk('local')->put(self::RUN_LEDGER, json_encode($data, JSON_PRETTY_PRINT));
    }
}
