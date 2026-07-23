<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Editor = 'editor';
    case Videographer = 'videographer';
    case Designer = 'designer';
    case Copywriter = 'copywriter';
    case SocialMedia = 'social-media';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Editor => 'Editor',
            self::Videographer => 'Videographer',
            self::Designer => 'Designer',
            self::Copywriter => 'Copywriter',
            self::SocialMedia => 'Social Media',
        };
    }

    public function badgeClass(): string
    {
        return 'role-' . $this->value;
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }

    public static function isManagerOrAbove(string $role): bool
    {
        return in_array($role, [self::SuperAdmin->value, self::Admin->value, self::Manager->value], true);
    }

    public static function isAdminOrAbove(string $role): bool
    {
        return in_array($role, [self::SuperAdmin->value, self::Admin->value], true);
    }
}
