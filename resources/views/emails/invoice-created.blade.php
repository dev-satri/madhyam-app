@component ('mail::message')
# New Invoice

Hi {{ $contactName }},

A new invoice has been generated for your account.

<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;">
<tr>
<td style="background-color: #f8fafc; padding: 28px 32px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Invoice</td>
<td style="padding: 8px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">#{{ $invoice->id }}</td>
</tr>
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Amount</td>
<td style="padding: 8px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">NPR {{ $netAmount }}</td>
</tr>
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Due Date</td>
<td style="padding: 8px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $dueDate }}</td>
</tr>
@if ($invoice->discount > 0)
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Discount</td>
<td style="padding: 8px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $invoice->discount }}%</td>
</tr>
@endif
@if(!empty($isInstallment))
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Payment Plan</td>
<td style="padding: 8px 0; font-size: 14px; color: #0e7490; font-weight: 600; text-align: right;">Installment{{ $installmentInfo ?? '' }}</td>
</tr>
@endif
</table>
</td>
</tr>
</table>

<div style="background-color: #f1f5f9; border: 1px dashed #94a3b8; border-radius: 6px; padding: 16px 20px; margin: 24px 0; text-align: center;">
<p style="font-size: 11px; color: #64748b; margin: 0;"><strong>⚡ Auto-generated invoice.</strong> Please contact administration for the original.</p>
</div>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 32px auto;">
<tr>
<td style="border-radius: 8px; background-color: #111827;">
<a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 14px 36px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">View Invoice</a>
</td>
</tr>
</table>

<p style="font-size: 13px; color: #64748b;">📥 A PDF copy of this invoice is attached to this email for your records.</p>

Please make payment before the due date to avoid any disruption to your services.

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
