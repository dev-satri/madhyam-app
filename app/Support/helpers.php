<?php

use App\Models\Client;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Support\NepaliDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

if (! function_exists('pkgName')) {
    function pkgName(?string $slug): string
    {
        if (! $slug) {
            return '';
        }
        static $cache = null;
        if ($cache === null) {
            $cache = Package::pluck('name', 'slug')->all();
        }

        return $cache[$slug] ?? ucfirst($slug);
    }
}

if (! function_exists('roleName')) {
    function roleName(?string $key): string
    {
        if (! $key) {
            return '';
        }
        $builtins = [
            'super-admin' => 'Super Admin',
            'admin' => 'Admin',
            'manager' => 'Manager',
            'editor' => 'Editor',
            'videographer' => 'Videographer',
            'designer' => 'Designer',
            'copywriter' => 'Copywriter',
            'social-media' => 'Social Media',
        ];
        if (isset($builtins[$key])) {
            return $builtins[$key];
        }
        static $customs = null;
        if ($customs === null) {
            $customs = CustomRole::pluck('name', 'role_key')->all();
        }

        return $customs[$key] ?? Str::title(str_replace('-', ' ', $key));
    }
}

if (! function_exists('badgeClass')) {
    function badgeClass(?string $status): string
    {
        if (! $status) {
            return 'badge';
        }

        return 'badge badge-' . Str::slug($status);
    }
}

if (! function_exists('getClientName')) {
    function getClientName(?int $id): string
    {
        if (! $id) {
            return '';
        }
        static $cache = [];
        if (! isset($cache[$id])) {
            $cache[$id] = optional(Client::find($id))->name ?? '';
        }

        return $cache[$id];
    }
}

if (! function_exists('getTeamName')) {
    function getTeamName(?int $id): string
    {
        if (! $id) {
            return '';
        }
        static $cache = [];
        if (! isset($cache[$id])) {
            $cache[$id] = optional(User::find($id))->name ?? '';
        }

        return $cache[$id];
    }
}

if (! function_exists('getDeptName')) {
    function getDeptName(?int $id): string
    {
        if (! $id) {
            return '';
        }
        static $cache = [];
        if (! isset($cache[$id])) {
            $cache[$id] = optional(Department::find($id))->name ?? '';
        }

        return $cache[$id];
    }
}

if (! function_exists('isOverdue')) {
    function isOverdue($date): bool
    {
        if (! $date) {
            return false;
        }
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $d->isPast() && ! $d->isToday();
    }
}

if (! function_exists('initials')) {
    function initials(?string $name): string
    {
        if (! $name) {
            return '?';
        }
        $parts = preg_split('/\s+/', trim($name));
        $out = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $out .= mb_substr($p, 0, 1);
        }

        return mb_strtoupper($out ?: '?');
    }
}

if (! function_exists('uid')) {
    function uid(string $prefix = ''): string
    {
        return $prefix . Str::random(8);
    }
}

if (! function_exists('fmtCurrency')) {
    function fmtCurrency($amount): string
    {
        $currency = Setting::current()->currency ?? 'NPR';
        $symbol = match ($currency) {
            'USD' => '$',
            'INR' => '₹',
            'NPR' => 'रु',
            default => '',
        };

        return $symbol . ' ' . number_format((float) $amount, 2);
    }
}

if (! function_exists('fmtDate')) {
    function fmtDate($date): string
    {
        if (! $date) {
            return '';
        }

        return NepaliDate::display($date);
    }
}

if (! function_exists('fmtDateTime')) {
    function fmtDateTime($ts): string
    {
        if (! $ts) {
            return '';
        }

        return NepaliDate::displayDateTime($ts);
    }
}

if (! function_exists('fmtDateShort')) {
    function fmtDateShort($date): string
    {
        if (! $date) {
            return '';
        }

        return NepaliDate::displayShort($date);
    }
}

if (! function_exists('fmtDateDayMonth')) {
    function fmtDateDayMonth($date): string
    {
        if (! $date) {
            return '';
        }

        return NepaliDate::displayDayMonth($date);
    }
}

if (! function_exists('fmtDateMonthYear')) {
    function fmtDateMonthYear($date): string
    {
        if (! $date) {
            return '';
        }

        return NepaliDate::displayMonthYear($date);
    }
}
