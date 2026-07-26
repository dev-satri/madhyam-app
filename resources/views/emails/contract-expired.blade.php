@component ('mail::message')
    # Contract Has Expired

    Hi {{ $contactName }},

    Your **{{ $packageName }}** contract with {{ config('app.name', 'Madhyam') }} expired **{{ $daysPast }} {{ $dayWord }} ago** on {{ $expiryDate }}.

    Your services may be disrupted until the contract is renewed. Please contact us as soon as possible to discuss renewal options.
    @component ('mail::panel')
        **Package**
        {{ $packageName }}
        **Expired On**
        {{ $expiryDate }}
        **Monthly Amount**
        NPR {{ $monthlyAmount }}
    @endcomponent

    @component ('mail::button', ['url' => $url, 'color' => 'error'])
        Contact Us to Renew
    @endcomponent
    We value our partnership and look forward to continuing to work with you.

    Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
