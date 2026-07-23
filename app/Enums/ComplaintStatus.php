<?php

namespace App\Enums;

enum ComplaintStatus: string
{
    case Open = 'open';
    case InProgress = 'in-progress';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In Progress',
            default => ucfirst($this->value),
        };
    }

    public function next(): self
    {
        return match ($this) {
            self::Open => self::InProgress,
            self::InProgress => self::Resolved,
            self::Resolved => self::Open,
        };
    }

    public function badgeClass(): string
    {
        return 'badge-' . $this->value;
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
