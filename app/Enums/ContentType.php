<?php

namespace App\Enums;

enum ContentType: string
{
    case Reel = 'reel';
    case Post = 'post';
    case Story = 'story';
    case Video = 'video';
    case Carousel = 'carousel';
    case Blog = 'blog';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeClass(): string
    {
        return 'badge-type-'.$this->value;
    }

    public static function videographerAllowed(): array
    {
        return [self::Video->value, self::Reel->value, self::Story->value];
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
