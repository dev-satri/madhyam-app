<?php

namespace App\Enums;

enum LeaveType: string
{
    case Sick = 'sick';
    case Casual = 'casual';
    case Annual = 'annual';
    case Personal = 'personal';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isPaid(): bool
    {
        return in_array($this, [self::Annual, self::Casual], true);
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
