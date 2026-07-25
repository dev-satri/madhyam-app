@component ('mail::message')
    # Welcome to {{ config('app.name', 'Madhyam') }}, {{ $client->contact ?? $client->name }}!

Thank you for choosing **{{ config('app.name', 'Madhyam') }}** as your creative partner. We're excited to work with you and bring your vision to life.
    @component ('mail::subheading')
        Your Partnership Summary
    @endcomponent

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
        @component ('mail::subheading')
            What's Included
        @endcomponent

        {{ $client->deliverables }}
    @endif

    @component ('mail::subheading')
        Your Client Portal
    @endcomponent
    You have access to our **Client Portal** where you can:
    <ul>
        <li>View and track your content calendar</li>
        <li>Approve content before publishing</li>
        <li>Monitor project workflows and progress</li>
        <li>Access invoices and payment history</li>
        <li>Submit and track complaints or requests</li>
    </ul>
    You will receive a separate email with your **login credentials** for the client portal.
    @component ('mail::subheading')
        Need Help?
    @endcomponent
    If you have any questions, feel free to reach out to your account manager or contact us at **{{ config('app.support_email', 'support@' . parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST)) }}**.

We look forward to a successful partnership!

Thanks,
     />
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
