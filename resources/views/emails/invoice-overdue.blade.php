@component ('mail::message')
# Payment Overdue

Hi {{ $contactName }},

<strong>Your payment is {{ $daysOverdue }} {{ $dayWord }} overdue.</strong> Please settle this amount as soon as possible to avoid any disruption to your services.
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0; border: 1px solid #fecaca; border-radius: 8px; overflow: hidden;">
<tr>
<td style="background-color: #fef2f2; padding: 20px 24px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Invoice</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">#{{ $invoice->id }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Amount Due</td>
<td style="padding: 4px 0; font-size: 14px; color: #dc2626; font-weight: 600; text-align: right;">NPR {{ $netAmount }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Was Due On</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $dueDate }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Days Overdue</td>
<td style="padding: 4px 0; font-size: 14px; color: #dc2626; font-weight: 600; text-align: right;">{{ $daysOverdue }} {{ $dayWord }}</td>
</tr>
</table>
</td>
</tr>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px auto;">
<tr>
<td style="border-radius: 8px; background-color: #dc2626;">
<a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">Pay Now</a>
</td>
</tr>
</table>

If you have any questions about this invoice or need to discuss payment arrangements, please don't hesitate to reach out.

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
