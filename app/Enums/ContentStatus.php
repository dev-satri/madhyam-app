<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Scripting = 'scripting';
    case InReview = 'in-review';
    case Revision = 'revision';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::InReview => 'In Review',
            default => ucfirst($this->value),
        };
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
