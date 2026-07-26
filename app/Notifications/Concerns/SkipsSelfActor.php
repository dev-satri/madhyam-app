<?php

namespace App\Notifications\Concerns;

use App\Models\User;

/**
 * Suppresses delivery when the notification's actor is also the recipient.
 *
 * Consuming classes must expose a public `?User $actor` property in the
 * constructor. Applies on every channel — the same person who assigned,
 * commented, or completed a task shouldn't receive a copy back.
 */
trait SkipsSelfActor
{
    public ?User $actor = null;

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $this->actor) {
            return true;
        }

        if (! $notifiable instanceof User) {
            return true;
        }

        return $notifiable->getKey() !== $this->actor->getKey();
    }
}
