@props (['date' => null, 'format' => 'default'])

@if ($date)
    @if ($format === 'short')
        {{ \App\Support\NepaliDate::displayShort($date) }}
    @elseif ($format === 'day-month')
        {{ \App\Support\NepaliDate::displayDayMonth($date) }}
    @elseif ($format === 'datetime')
        {{ \App\Support\NepaliDate::displayDateTime($date) }}
    @elseif ($format === 'month-year')
        {{ \App\Support\NepaliDate::displayMonthYear($date) }}
    @else
        {{ \App\Support\NepaliDate::display($date) }}
    @endif
@endif
