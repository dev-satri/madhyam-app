<?php

namespace App\Enums;

enum SalaryStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Approved = 'approved';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeClass(): string
    {
        return 'badge-'.$this->value;
    }

    public function next(): self
    {
        return match ($this) {
            self::Pending => self::Paid,
            self::Paid => self::Approved,
            self::Approved => self::Pending,
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
