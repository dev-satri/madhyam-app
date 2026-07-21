<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected const CAP = 50;

    public function sendNotification(string $text, string $type = 'info', ?string $link = null, string $forRole = 'all'): Notification
    {
        $note = Notification::create([
            'text' => $text,
            'type' => $type,
            'link' => $link,
            'for_role' => $forRole,
            'read' => false,
        ]);
        $this->trim();

        return $note;
    }

    public function markRead(int $id): void
    {
        Notification::whereKey($id)->update(['read' => true]);
    }

    public function markAllRead(?string $role = null): void
    {
        $q = Notification::query()->where('read', false);
        if ($role) {
            $q->whereIn('for_role', [$role, 'all']);
        }
        $q->update(['read' => true]);
    }

    public function emailNotify(string $to, string $subject, string $body): void
    {
        Log::info('emailNotify (stub)', compact('to', 'subject', 'body'));
    }

    public function notifyTaskAssignment(Task $task): void
    {
        if (! $task->assigneeUser) {
            return;
        }
        $this->sendNotification(
            text: "New task assigned: {$task->title}",
            type: 'info',
            link: route('tasks', absolute: false),
            forRole: $task->assigneeUser->role,
        );
    }

    protected function trim(): void
    {
        $count = Notification::count();
        if ($count > self::CAP) {
            $excess = $count - self::CAP;
            $ids = Notification::orderBy('id')->limit($excess)->pluck('id');
            Notification::whereIn('id', $ids)->delete();
        }
    }
}
