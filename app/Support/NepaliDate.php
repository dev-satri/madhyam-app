<?php

namespace App\Support;

use Anuzpandey\LaravelNepaliDate\LaravelNepaliDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NepaliDate
{
    private static ?bool $isBs = null;

    private const BS_MONTHS = [
        1 => 'Baisakh', 2 => 'Jestha', 3 => 'Asar',
        4 => 'Shrawan', 5 => 'Bhadra', 6 => 'Aswin',
        7 => 'Kartik', 8 => 'Mangsir', 9 => 'Poush',
        10 => 'Magh', 11 => 'Falgun', 12 => 'Chaitra',
    ];

    private const BS_MONTHS_NP = [
        1 => 'बैशाख', 2 => 'जेठ', 3 => 'असार',
        4 => 'श्रावण', 5 => 'भदौ', 6 => 'असोज',
        7 => 'कार्तिक', 8 => 'मंसिर', 9 => 'पुस',
        10 => 'माघ', 11 => 'फाल्गुन', 12 => 'चैत्र',
    ];

    public static function isBs(): bool
    {
        if (self::$isBs === null) {
            $setting = DB::table('settings')->where('id', 1)->first();
            self::$isBs = $setting && $setting->date_format === 'BS';
        }

        return self::$isBs;
    }

    public static function resetCache(): void
    {
        self::$isBs = null;
    }

    public static function display(Carbon|string|null $date): string
    {
        if (! $date) {
            return '';
        }

        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        if (self::isBs()) {
            return LaravelNepaliDate::from($date->format('Y-m-d'))
                ->toNepaliDate('D, j F Y', 'en');
        }

        return $date->format('M d, Y');
    }

    public static function displayShort(Carbon|string|null $date): string
    {
        if (! $date) {
            return '';
        }

        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        if (self::isBs()) {
            return LaravelNepaliDate::from($date->format('Y-m-d'))
                ->toNepaliDate('j F Y', 'en');
        }

        return $date->format('M d, Y');
    }

    public static function displayDayMonth(Carbon|string|null $date): string
    {
        if (! $date) {
            return '';
        }

        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        if (self::isBs()) {
            return LaravelNepaliDate::from($date->format('Y-m-d'))
                ->toNepaliDate('j M', 'en');
        }

        return $date->format('M j');
    }

    public static function displayDateTime(Carbon|string|null $datetime): string
    {
        if (! $datetime) {
            return '';
        }

        $datetime = $datetime instanceof Carbon ? $datetime : Carbon::parse($datetime);

        if (self::isBs()) {
            return LaravelNepaliDate::from($datetime->format('Y-m-d'))
                ->toNepaliDate('D, j F Y', 'en')
                . ' ' . $datetime->format('g:i A');
        }

        return $datetime->format('M d, Y g:i A');
    }

    public static function displayMonthYear(Carbon|string|null $date): string
    {
        if (! $date) {
            return '';
        }

        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        if (self::isBs()) {
            return LaravelNepaliDate::from($date->format('Y-m-d'))
                ->toNepaliDate('F Y', 'en');
        }

        return $date->format('F Y');
    }

    public static function displayInputValue(Carbon|string|null $date): string
    {
        if (! $date) {
            return '';
        }

        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        if (self::isBs()) {
            return LaravelNepaliDate::from($date->format('Y-m-d'))
                ->toNepaliDate('Y-m-d', 'en');
        }

        return $date->format('Y-m-d');
    }

    public static function toAd(string $bsDate): string
    {
        return LaravelNepaliDate::from($bsDate, 'Y-m-d', 'np')
            ->toEnglishDate('Y-m-d');
    }

    public static function bsMonthName(int $month): string
    {
        return self::BS_MONTHS[$month] ?? '';
    }

    public static function bsMonthNameNp(int $month): string
    {
        return self::BS_MONTHS_NP[$month] ?? '';
    }

    public static function bsDaysInMonth(int $month, int $year): int
    {
        return LaravelNepaliDate::daysInMonth($month, $year);
    }

    public static function bsCalendarDays(int $bsYear, int $bsMonth): array
    {
        $totalDays = self::bsDaysInMonth($bsMonth, $bsYear);

        $firstDayBs = sprintf('%04d-%02d-01', $bsYear, $bsMonth);
        $firstDayAd = self::toAd($firstDayBs);
        $startOfWeek = Carbon::parse($firstDayAd)->dayOfWeek;

        $days = [];

        for ($i = 0; $i < $startOfWeek; $i++) {
            $days[] = ['day' => 0, 'bs_date' => '', 'ad_date' => '', 'other_month' => true];
        }

        for ($day = 1; $day <= $totalDays; $day++) {
            $bsDate = sprintf('%04d-%02d-%02d', $bsYear, $bsMonth, $day);
            $adDate = self::toAd($bsDate);
            $days[] = [
                'day' => $day,
                'bs_date' => $bsDate,
                'ad_date' => $adDate,
                'other_month' => false,
            ];
        }

        $remaining = (7 - (count($days) % 7)) % 7;
        for ($i = 0; $i < $remaining; $i++) {
            $days[] = ['day' => 0, 'bs_date' => '', 'ad_date' => '', 'other_month' => true];
        }

        return $days;
    }

    public static function adToBsArray(string $adDate): array
    {
        $dto = LaravelNepaliDate::from($adDate)->toNepaliDateArray();

        return [
            'year' => (int) $dto->year,
            'month' => (int) $dto->month,
            'day' => (int) $dto->day,
            'month_name' => $dto->monthName,
            'day_name' => $dto->dayName,
        ];
    }

    public static function getCurrentBsDate(): array
    {
        return self::adToBsArray(now()->format('Y-m-d'));
    }
}
