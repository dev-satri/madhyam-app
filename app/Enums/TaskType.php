<?php

namespace App\Enums;

enum TaskType: string
{
    case Task = 'task';
    case Shoot = 'shoot';
    case Editing = 'editing';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
