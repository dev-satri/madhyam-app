<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpireFilesCommand extends Command
{
    protected $signature = 'files:expire';

    protected $description = 'Delete expired files and their records';

    public function handle(): int
    {
        $expired = DB::table('file_expiries')
            ->where('expiry_date', '<', now())
            ->get();

        $deleted = 0;

        foreach ($expired as $expiry) {
            $file = DB::table('files')->where('id', $expiry->file_id)->first();
            if ($file) {
                if (Storage::exists($file->path)) {
                    Storage::delete($file->path);
                }
                DB::table('files')->where('id', $file->id)->delete();
                $deleted++;
            }
            DB::table('file_expiries')->where('id', $expiry->id)->delete();
        }

        $this->info("Deleted {$deleted} expired file(s).");

        return Command::SUCCESS;
    }
}
