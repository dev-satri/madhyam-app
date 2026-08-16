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

        // Only suppress when actor and notifiable are the same model type & key
        if (get_class($notifiable) !== get_class($this->actor)) {
            return true;
        }

        return $notifiable->getKey() !== $this->actor->getKey();
    }
}
