@component ('mail::message')
# Payment Received ✓

Hi {{ $contactName }},

We've received your payment for **Invoice #{{ $invoice->id }}**. Here are the details:

<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0; border: 1px solid #d1fae5; border-radius: 10px; overflow: hidden;">
<tr>
<td style="background-color: #ecfdf5; padding: 28px 32px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Invoice</td>
<td style="padding: 8px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">#{{ $invoice->id }}</td>
</tr>
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Payment Received</td>
<td style="padding: 8px 0; font-size: 16px; color: #059669; font-weight: 700; text-align: right;">NPR {{ $paymentAmount }}</td>
</tr>
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Payment Method</td>
<td style="padding: 8px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">
@if($methodRaw === 'cash')💵 @elseif($methodRaw === 'bank')🏦 @elseif($methodRaw === 'card')💳 @elseif($methodRaw === 'cheque')📄 @elseif($methodRaw === 'esewa' || $methodRaw === 'khalti')📱 @else📋 @endif{{ $method }}
</td>
</tr>
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Payment Date</td>
<td style="padding: 8px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $paymentDate }}</td>
</tr>
@if($note)
<tr>
<td style="padding: 8px 0; font-size: 14px; color: #64748b;">Note</td>
<td style="padding: 8px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $note }}</td>
</tr>
@endif
</table>
</td>
</tr>
</table>

{{-- Payment Summary --}}
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;">
<tr>
<td style="background-color: #f8fafc; padding: 24px 32px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 8px 0; font-size: 13px; color: #64748b;">Invoice Total</td>
<td style="padding: 8px 0; font-size: 13px; color: #1e293b; font-weight: 600; text-align: right;">NPR {{ $netAmount }}</td>
</tr>
<tr>
<td style="padding: 8px 0; font-size: 13px; color: #64748b;">Total Paid So Far</td>
<td style="padding: 8px 0; font-size: 13px; color: #059669; font-weight: 600; text-align: right;">NPR {{ $totalPaid }}</td>
</tr>
@if(!$isFullyPaid)
<tr>
<td style="padding: 8px 0; font-size: 13px; color: #64748b;">Remaining Balance</td>
<td style="padding: 8px 0; font-size: 13px; color: #dc2626; font-weight: 700; text-align: right;">NPR {{ $remaining }}</td>
</tr>
@endif
</table>
</td>
</tr>
</table>

{{-- Installment Progress --}}
@if($isInstallment && $installmentContext)
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0; border: 1px solid #a5f3fc; border-radius: 10px; overflow: hidden;">
<tr>
<td style="background-color: #ecfeff; padding: 24px 32px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td colspan="2" style="padding: 0 0 12px 0; font-size: 14px; color: #0e7490; font-weight: 700;">
📅 Installment Plan Progress
</td>
</tr>
<tr>
<td style="padding: 8px 0; font-size: 13px; color: #64748b;">Current Installment</td>
<td style="padding: 8px 0; font-size: 13px; color: #0e7490; font-weight: 600; text-align: right;">{{ $installmentContext['current_installment_number'] }} of {{ $installmentContext['total_installments'] }}</td>
</tr>
<tr>
<td style="padding: 8px 0; font-size: 13px; color: #64748b;">Per Installment</td>
<td style="padding: 8px 0; font-size: 13px; color: #1e293b; font-weight: 600; text-align: right;">NPR {{ $installmentContext['amount_per_installment'] }}</td>
</tr>
<tr>
<td style="padding: 8px 0; font-size: 13px; color: #64748b;">Fully Paid</td>
<td style="padding: 8px 0; font-size: 13px; color: #059669; font-weight: 600; text-align: right;">{{ $installmentContext['paid_installments'] }} of {{ $installmentContext['total_installments'] }}</td>
</tr>
@if(!$isFullyPaid && (float) str_replace(',', '', $installmentContext['current_installment_remaining']) > 0)
<tr>
<td style="padding: 8px 0; font-size: 13px; color: #64748b;">Next Payment Due</td>
<td style="padding: 8px 0; font-size: 13px; color: #dc2626; font-weight: 700; text-align: right;">NPR {{ $installmentContext['current_installment_remaining'] }}</td>
</tr>
@endif
</table>
</td>
</tr>
</table>
@endif

{{-- Status Message --}}
@if($isFullyPaid)
<div style="background-color: #d1fae5; border: 2px solid #a7f3d0; border-radius: 10px; padding: 28px 24px; margin: 24px 0; text-align: center;">
<div style="font-size: 28px; margin-bottom: 10px;">🎉</div>
<strong style="color: #065f46; font-size: 18px;">This invoice has been fully paid!</strong>
<p style="color: #047857; font-size: 14px; margin: 12px 0 0 0;">Thank you for your payment. No further action is required.</p>
</div>
@else
<div style="background-color: #fef3c7; border: 1px solid #fcd34d; border-radius: 10px; padding: 20px 24px; margin: 24px 0;">
<strong style="color: #92400e; font-size: 14px;">⏳ Outstanding Balance: NPR {{ $remaining }}</strong>
<p style="color: #a16207; font-size: 13px; margin: 10px 0 0 0;">Please complete the remaining payment before the due date to avoid any service disruption.</p>
</div>
@endif

<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 32px auto;">
<tr>
<td style="border-radius: 8px; background-color: {{ $isFullyPaid ? '#059669' : '#111827' }};">
<a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 14px 36px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">{{ $isFullyPaid ? 'View Receipt' : 'Pay Now' }}</a>
</td>
</tr>
</table>

If you have any questions about this payment, please don't hesitate to reach out.

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
