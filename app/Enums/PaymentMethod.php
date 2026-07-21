<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Card = 'card';
    case Cheque = 'cheque';
    case Esewa = 'esewa';
    case Khalti = 'khalti';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Esewa => 'eSewa',
            default => ucfirst($this->value),
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
