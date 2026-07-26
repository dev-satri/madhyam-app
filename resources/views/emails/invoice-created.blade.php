@component ('mail::message')
    # New Invoice

    Hi {{ $contactName }},

    A new invoice has been generated for your account.
    @component ('mail::panel')
        **Invoice**
        #{{ $invoice->id }}
        **Amount**
        NPR {{ $netAmount }}
        **Due Date**
        {{ $dueDate }}

        @if ($invoice->discount > 0)
            **Discount**
            {{ $invoice->discount }}%
        @endif
    @endcomponent

    @component ('mail::button', ['url' => $url, 'color' => 'primary'])
        View Invoice
    @endcomponent
    Please make payment before the due date to avoid any disruption to your services.

    Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
