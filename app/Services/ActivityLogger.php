<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Carbon;

class ActivityLogger
{
    protected const CAP = 100;

    public function record(?User $user, string $text): ActivityLog
    {
        $log = ActivityLog::create([
            'user_id' => $user?->id,
            'user' => $user?->name,
            'text' => $text,
            'time' => Carbon::now(),
        ]);
        $this->trim();

        return $log;
    }

    protected function trim(): void
    {
        $count = ActivityLog::count();
        if ($count > self::CAP) {
            $excess = $count - self::CAP;
            $ids = ActivityLog::orderBy('id')->limit($excess)->pluck('id');
            ActivityLog::whereIn('id', $ids)->delete();
        }
    }
}
