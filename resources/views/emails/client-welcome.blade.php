@component ('mail::message')
    # Welcome to {{ config('app.name', 'Madhyam') }}, {{ $client->contact ?? $client->name }}!

Thank you for choosing **{{ config('app.name', 'Madhyam') }}** as your creative partner. We're excited to work with you and bring your vision to life.

## Your Partnership Summary
    @component ('mail::panel')
        **Client Name**
        {{ $client->name }}
        @if ($packageName)
            **Package**
            {{ $packageName }}
        @endif
        @if ($client->amount)
            **Monthly Investment**
NPR {{ number_format($client->amount, 2) }}
        @endif
        @if ($client->contract_start)
            **Contract Start**
            {{ $client->contract_start->format('M d, Y') }}
        @endif
        @if ($client->contract_end)
            **Contract End**
            {{ $client->contract_end->format('M d, Y') }}
        @endif
    @endcomponent

    @if ($client->deliverables)
        ## What's Included
        {{ $client->deliverables }}
    @endif
    ## Your Client Portal

You have access to our **Client Portal** where you can:

- View and track your content calendar
- Approve content before publishing
- Monitor project workflows and progress
- Access invoices and payment history
- Submit and track complaints or requests

You will receive a separate email with your **login credentials** for the client portal.

## Need Help?

If you have any questions, feel free to reach out to your account manager or contact us at **{{ config('app.support_email', 'support@' . parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST)) }}**.

We look forward to a successful partnership!

Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
