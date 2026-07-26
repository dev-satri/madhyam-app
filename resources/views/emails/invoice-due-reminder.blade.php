@component ('mail::message')
# Payment Due in {{ $daysUntilDue }} {{ $dayWord }}

Hi {{ $contactName }},

@if ($daysUntilDue <= 1)
<strong>This is a final reminder</strong> — your payment is due tomorrow.
@elseif ($daysUntilDue <= 3)
Your payment is due in <strong>{{ $daysUntilDue }} days</strong>. Please ensure timely payment.
@else
This is a friendly reminder that your payment is due in <strong>{{ $daysUntilDue }} days</strong>.
@endif
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
<tr>
<td style="background-color: #f8fafc; padding: 20px 24px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Invoice</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">#{{ $invoice->id }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Amount Due</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">NPR {{ $netAmount }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Due Date</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $dueDate }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Status</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ ucfirst($invoice->payment_status) }}</td>
</tr>
</table>
</td>
</tr>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px auto;">
<tr>
<td style="border-radius: 8px; background-color: {{ $urgency === 'error' ? '#dc2626' : ($urgency === 'warning' ? '#d97706' : '#111827') }};">
<a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">Pay Now</a>
</td>
</tr>
</table>

If you've already made payment, please disregard this reminder.

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
