<?php

namespace App\Enums;

enum Priority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
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
