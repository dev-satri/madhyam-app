@component ('mail::message')
    # Payment Due in {{ $daysUntilDue }} {{ $dayWord }}
    Hi {{ $contactName }},
    @if ($daysUntilDue <= 1)
        **This is a final reminder** — your payment is due tomorrow.
    @elseif ($daysUntilDue <= 3)
        Your payment is due in **{{ $daysUntilDue }} days**. Please ensure timely payment.
    @else
        This is a friendly reminder that your payment is due in **{{ $daysUntilDue }} days**.
    @endif

    @component ('mail::panel')
        **Invoice**
        #{{ $invoice->id }}
        **Amount Due**
        NPR {{ $netAmount }}
        **Due Date**
        {{ $dueDate }}
        **Status**
        {{ ucfirst($invoice->payment_status) }}
    @endcomponent

    @component ('mail::button', ['url' => $url, 'color' => $urgency === 'error' ? 'error' : 'primary'])
        Pay Now
    @endcomponent
    If you've already made payment, please disregard this reminder.

    Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
