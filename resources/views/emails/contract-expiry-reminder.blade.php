@component ('mail::message')
# Contract Expiring Soon

Hi {{ $contactName }},

@if ($daysUntil <= 1)
Your <strong>{{ $packageName }}</strong> contract with {{ config('app.name', 'Madhyam') }} expires <strong>tomorrow</strong>.
@elseif ($daysUntil <= 3)
Your <strong>{{ $packageName }}</strong> contract with {{ config('app.name', 'Madhyam') }} expires in <strong>{{ $daysUntil }} days</strong>.
@else
Your <strong>{{ $packageName }}</strong> contract with {{ config('app.name', 'Madhyam') }} is expiring on <strong>{{ $expiryDate }}</strong>.
@endif

To continue enjoying our services without interruption, please get in touch with us to renew your contract.
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
<tr>
<td style="background-color: #f8fafc; padding: 20px 24px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Package</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $packageName }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Expiry Date</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $expiryDate }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Monthly Amount</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">NPR {{ $monthlyAmount }}</td>
</tr>
</table>
</td>
</tr>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px auto;">
<tr>
<td style="border-radius: 8px; background-color: #111827;">
<a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">View Billing</a>
</td>
</tr>
</table>

If you have any questions about renewal options, feel free to reach out to our team.

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
