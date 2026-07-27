<?php

namespace App\Console\Commands;

use App\Services\TrashService;
use Illuminate\Console\Command;

class PurgeExpiredTrash extends Command
{
    protected $signature = 'trash:purge';

    protected $description = 'Permanently delete trash items older than 7 days';

    public function handle(TrashService $trashService): int
    {
        $count = $trashService->purgeExpired();

        $this->info("Purged {$count} expired trash item(s).");

        return Command::SUCCESS;
    }
}
