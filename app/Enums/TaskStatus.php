<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in-progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To Do',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
        };
    }

    public function next(): self
    {
        return match ($this) {
            self::Todo => self::InProgress,
            self::InProgress => self::Completed,
            self::Completed => self::Todo,
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
