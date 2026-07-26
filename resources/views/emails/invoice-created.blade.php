@component ('mail::message')
# New Invoice

Hi {{ $contactName }},

A new invoice has been generated for your account.
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
<tr>
<td style="background-color: #f8fafc; padding: 20px 24px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Invoice</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">#{{ $invoice->id }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Amount</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">NPR {{ $netAmount }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Due Date</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $dueDate }}</td>
</tr>
@if ($invoice->discount > 0)
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Discount</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $invoice->discount }}%</td>
</tr>
@endif
</table>
</td>
</tr>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px auto;">
<tr>
<td style="border-radius: 8px; background-color: #111827;">
<a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">View Invoice</a>
</td>
</tr>
</table>

Please make payment before the due date to avoid any disruption to your services.

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
