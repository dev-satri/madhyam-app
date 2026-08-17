<?php

namespace App\Notifications\Concerns;

/**
 * Suppresses delivery when the notification's actor is also the recipient.
 *
 * Consuming classes must expose a public `mixed $actor` property in the
 * constructor (typically a User or ClientAccount). Applies on every channel
 * — the same person who assigned, commented, or completed a task shouldn't
 * receive a copy back.
 */
trait SkipsSelfActor
{
    public mixed $actor = null;

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $this->actor) {
            return true;
        }

        // Exact object match
        if ($notifiable === $this->actor) {
            return false;
        }

        // Same model class and primary key match
        if (get_class($notifiable) === get_class($this->actor) && method_exists($notifiable, 'getKey') && method_exists($this->actor, 'getKey')) {
            if ((string) $notifiable->getKey() === (string) $this->actor->getKey()) {
                return false;
            }
        }

        // Same email address match across different model types (e.g. User vs ClientAccount)
        if (isset($notifiable->email, $this->actor->email) && ! empty($notifiable->email) && ! empty($this->actor->email)) {
            if (strtolower(trim($notifiable->email)) === strtolower(trim($this->actor->email))) {
                return false;
            }
        }

        return true;
    }
}
