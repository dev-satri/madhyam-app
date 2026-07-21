<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Half = 'half';
    case Full = 'full';
    case Installment = 'installment';
    case Discount = 'discount';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeClass(): string
    {
        return 'badge-'.$this->value;
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
