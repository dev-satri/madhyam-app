<?php

namespace App\Notifications\Channels;

use App\Models\Notification as InAppNotification;
use App\Services\NotificationService;
use Illuminate\Notifications\Notification;

/**
 * Custom "in_app" notification channel.
 *
 * Bridges Laravel's Notification pipeline into the app's existing bell
 * dropdown table (`notifications`) so we don't have to migrate the bell UI
 * to Laravel's polymorphic `notifiable_type/notifiable_id` shape.
 *
 * Consuming notification classes implement `toInApp($notifiable)` returning
 * an associative array with keys: text, type (default 'info'), link (nullable).
 */
class InAppDatabaseChannel
{
    public function __construct(protected NotificationService $notifications) {}

    public function send(object $notifiable, Notification $notification): ?InAppNotification
    {
        if (! method_exists($notification, 'toInApp')) {
            return null;
        }

        /** @var array{text: string, type?: string, link?: ?string} $payload */
        $payload = $notification->toInApp($notifiable);

        if (empty($payload['text'])) {
            return null;
        }

        $row = InAppNotification::create([
            'text' => $payload['text'],
            'type' => $payload['type'] ?? 'info',
            'link' => $payload['link'] ?? null,
            // Sentinel — the `for_role` column is NOT NULL in the legacy schema
            // and role-based readers filter on real role strings (super-admin,
            // manager, graphic-designer, ...). 'user' matches none of them, so
            // per-user rows are ONLY discoverable via user_id.
            'for_role' => 'user',
            'user_id' => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
            'read' => false,
        ]);

        // Enforce the same 50-row cap the legacy service uses.
        $this->notifications->trimForUser();

        return $row;
    }
}
