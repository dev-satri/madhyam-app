@component ('mail::message')
    # Contract Expiring Soon

    Hi {{ $contactName }},
    @if ($daysUntil <= 1)
        Your **{{ $packageName }}** contract with {{ config('app.name', 'Madhyam') }} expires **tomorrow**.
    @elseif ($daysUntil <= 3)
        Your **{{ $packageName }}** contract with {{ config('app.name', 'Madhyam') }} expires in **{{ $daysUntil }} days**.
    @else
        Your **{{ $packageName }}** contract with {{ config('app.name', 'Madhyam') }} is expiring on **{{ $expiryDate }}**.
    @endif
    To continue enjoying our services without interruption, please get in touch with us to renew your contract.
    @component ('mail::panel')
        **Package**
        {{ $packageName }}
        **Expiry Date**
        {{ $expiryDate }}
        **Monthly Amount**
        NPR {{ $monthlyAmount }}
    @endcomponent

    @component ('mail::button', ['url' => $url, 'color' => 'primary'])
        View Billing
    @endcomponent
    If you have any questions about renewal options, feel free to reach out to our team.

    Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
