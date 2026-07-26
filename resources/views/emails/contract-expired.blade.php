@component ('mail::message')
# Contract Has Expired

Hi {{ $contactName }},

Your <strong>{{ $packageName }}</strong> contract with {{ config('app.name', 'Madhyam') }} expired <strong>{{ $daysPast }} {{ $dayWord }} ago</strong> on {{ $expiryDate }}.

Your services may be disrupted until the contract is renewed. Please contact us as soon as possible to discuss renewal options.
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0; border: 1px solid #fecaca; border-radius: 8px; overflow: hidden;">
<tr>
<td style="background-color: #fef2f2; padding: 20px 24px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Package</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $packageName }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Expired On</td>
<td style="padding: 4px 0; font-size: 14px; color: #dc2626; font-weight: 600; text-align: right;">{{ $expiryDate }}</td>
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
<td style="border-radius: 8px; background-color: #dc2626;">
<a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">Contact Us to Renew</a>
</td>
</tr>
</table>

We value our partnership and look forward to continuing to work with you.

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
