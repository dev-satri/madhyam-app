<?php

namespace App\Support;

/**
 * Tolerant reader/writer for the JSON-cast `contents.platform` and `contents.type` columns.
 *
 * Rows are stored as arrays:
 *   - ["instagram", "facebook"]  → the two selected platforms
 *   - ["all"]                    → sentinel meaning every current + future platform
 *
 * Every Blade template / controller / seeder / query MUST route reads through
 * these helpers instead of touching the raw value. See MEMORY.md
 * (madhyam-json-field-shape-normalizer) for the pattern.
 */
final class ContentTags
{
    public const PLATFORMS = ['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'linkedin'];

    public const TYPES = ['reel', 'post', 'story', 'video', 'carousel', 'blog'];

    public const ALL = 'all';

    /**
     * Coerce any legacy scalar, null, JSON-encoded string, or array into a clean array of known values.
     * Unknown/empty inputs return an empty array — callers can decide the fallback.
     */
    public static function normalize(mixed $value, string $kind): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $trim = trim($value);
            if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '"')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                } elseif (is_string($decoded)) {
                    $value = [$decoded];
                } else {
                    $value = [$trim];
                }
            } else {
                $value = [$trim];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        $allowed = self::allowed($kind);

        $out = [];
        foreach ($value as $v) {
            if (! is_string($v)) {
                continue;
            }
            $v = strtolower(trim($v));
            if ($v === self::ALL || in_array($v, $allowed, true)) {
                $out[] = $v;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Expand ["all"] → the canonical value list. Any other array is returned as-is.
     */
    public static function expand(array $values, string $kind): array
    {
        if (in_array(self::ALL, $values, true)) {
            return self::allowed($kind);
        }

        return $values;
    }

    /**
     * Primary/representative value for scalar contexts:
     *   - CSS class interpolation (bg-{platform}-500)
     *   - approvals.type / workflows.type copies
     *   - icon-map lookups
     *
     * For ["all"] returns the first canonical value ('instagram' / 'reel').
     * For empty input returns the fallback.
     */
    public static function primary(array $values, string $kind, ?string $fallback = null): string
    {
        $expanded = self::expand($values, $kind);
        if (! empty($expanded)) {
            return $expanded[0];
        }

        return $fallback ?? self::allowed($kind)[0];
    }

    /**
     * Human label: "Instagram, Facebook" for a multi-selection,
     * "All platforms" / "All types" for the sentinel, "—" for empty.
     */
    public static function label(array $values, string $kind): string
    {
        if (empty($values)) {
            return '—';
        }

        if (in_array(self::ALL, $values, true)) {
            return $kind === 'platform' ? 'All platforms' : 'All types';
        }

        return implode(', ', array_map('ucfirst', $values));
    }

    /**
     * Are all canonical values selected (either literally or via the "all" sentinel)?
     */
    public static function isAll(array $values, string $kind): bool
    {
        if (in_array(self::ALL, $values, true)) {
            return true;
        }

        $allowed = self::allowed($kind);
        sort($values);
        sort($allowed);

        return $values === $allowed;
    }

    private static function allowed(string $kind): array
    {
        return $kind === 'platform' ? self::PLATFORMS : self::TYPES;
    }
}
