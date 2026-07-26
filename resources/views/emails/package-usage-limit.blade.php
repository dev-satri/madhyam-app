@component ('mail::message')
    # {{ $category }} Limit Reached

    Hi {{ $contactName }},

    Your **{{ $packageName }}** package {{ strtolower($category) }} limit has been reached. You've used **{{ $used }} out of {{ $limit }}**.

    To continue creating {{ strtolower($category) }}, please upgrade your package.
    @component ('mail::panel')
        **Category**
        {{ $category }}
        **Used**
        {{ $used }}
        **Limit**
        {{ $limit }}
        **Status**
        Limit Reached
    @endcomponent

    @component ('mail::button', ['url' => $url, 'color' => 'error'])
        Upgrade Package
    @endcomponent
    If you have questions about upgrade options, feel free to reach out.

    Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
