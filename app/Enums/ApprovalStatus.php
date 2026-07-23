<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Revision = 'revision';
    case Rejected = 'rejected';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
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
