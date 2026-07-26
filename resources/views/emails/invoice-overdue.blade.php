@component ('mail::message')
    # Payment Overdue

    Hi {{ $contactName }},

    **Your payment is {{ $daysOverdue }} {{ $dayWord }} overdue.** Please settle this amount as soon as possible to avoid any disruption to your services.
    @component ('mail::panel')
        **Invoice**
        #{{ $invoice->id }}
        **Amount Due**
        NPR {{ $netAmount }}
        **Was Due On**
        {{ $dueDate }}
        **Days Overdue**
        {{ $daysOverdue }} {{ $dayWord }}
    @endcomponent

    @component ('mail::button', ['url' => $url, 'color' => 'error'])
        Pay Now
    @endcomponent
    If you have any questions about this invoice or need to discuss payment arrangements, please don't hesitate to reach out.

    Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
