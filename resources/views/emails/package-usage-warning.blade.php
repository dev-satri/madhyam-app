@component ('mail::message')
    # {{ $category }} Usage at {{ $percent }}%

    Hi {{ $contactName }},

    Your **{{ $packageName }}** package {{ strtolower($category) }} usage has reached **{{ $percent }}%** of your monthly limit.

    Consider upgrading your plan to avoid hitting the ceiling.
    @component ('mail::panel')
        **Category**
        {{ $category }}
        **Used**
        {{ $used }}
        **Limit**
        {{ $limit }}
        **Usage**
        {{ $percent }}%
    @endcomponent

    @component ('mail::button', ['url' => $url, 'color' => 'warning'])
        View Dashboard
    @endcomponent
    If you need more capacity, reach out to discuss upgrade options.

    Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
