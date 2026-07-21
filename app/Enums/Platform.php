<?php

namespace App\Enums;

enum Platform: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case Tiktok = 'tiktok';
    case Youtube = 'youtube';
    case Twitter = 'twitter';
    case LinkedIn = 'linkedin';

    public function label(): string
    {
        return match ($this) {
            self::Tiktok => 'TikTok',
            self::Youtube => 'YouTube',
            self::LinkedIn => 'LinkedIn',
            default => ucfirst($this->value),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Instagram => 'fa-brands fa-instagram',
            self::Facebook => 'fa-brands fa-facebook',
            self::Tiktok => 'fa-brands fa-tiktok',
            self::Youtube => 'fa-brands fa-youtube',
            self::Twitter => 'fa-brands fa-twitter',
            self::LinkedIn => 'fa-brands fa-linkedin',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Instagram => '#E4405F',
            self::Facebook => '#1877F2',
            self::Tiktok => '#000000',
            self::Youtube => '#FF0000',
            self::Twitter => '#1DA1F2',
            self::LinkedIn => '#0A66C2',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
