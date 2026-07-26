@component ('mail::message')
# {{ $category }} Limit Reached

Hi {{ $contactName }},

Your <strong>{{ $packageName }}</strong> package {{ strtolower($category) }} limit has been reached. You've used <strong>{{ $used }} out of {{ $limit }}</strong>.

To continue creating {{ strtolower($category) }}, please upgrade your package.
<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0; border: 1px solid #fecaca; border-radius: 8px; overflow: hidden;">
<tr>
<td style="background-color: #fef2f2; padding: 20px 24px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Category</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $category }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Used</td>
<td style="padding: 4px 0; font-size: 14px; color: #dc2626; font-weight: 600; text-align: right;">{{ $used }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Limit</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $limit }}</td>
</tr>
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Status</td>
<td style="padding: 4px 0; font-size: 14px; color: #dc2626; font-weight: 600; text-align: right;">Limit Reached</td>
</tr>
</table>
</td>
</tr>
</table>
@if (!empty($deliverables))
<p style="font-size: 13px; color: #64748b; margin: 20px 0 8px 0; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Deliverables this month</p>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 16px 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
<tr style="background-color: #f8fafc;">
<th align="left" style="padding: 10px 14px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0;">Type</th>
<th align="right" style="padding: 10px 14px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0;">Used</th>
<th align="right" style="padding: 10px 14px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0;">Limit</th>
<th align="right" style="padding: 10px 14px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0;">%</th>
</tr>
@foreach ($deliverables as $d)
@php
$rowColor = $d['percent'] >= 100 ? '#dc2626' : ($d['percent'] >= 80 ? '#d97706' : '#1e293b');
$rowBg = $d['percent'] >= 100 ? '#fef2f2' : ($d['percent'] >= 80 ? '#fffbeb' : '#ffffff');
@endphp
<tr style="background-color: {{ $rowBg }};">
<td style="padding: 10px 14px; font-size: 14px; color: #1e293b; font-weight: 500; border-bottom: 1px solid #f1f5f9;">{{ ucfirst($d['type']) }}</td>
<td align="right" style="padding: 10px 14px; font-size: 14px; color: {{ $rowColor }}; font-weight: 600; border-bottom: 1px solid #f1f5f9;">{{ $d['used'] }}</td>
<td align="right" style="padding: 10px 14px; font-size: 14px; color: #64748b; border-bottom: 1px solid #f1f5f9;">{{ $d['limit'] }}</td>
<td align="right" style="padding: 10px 14px; font-size: 14px; color: {{ $rowColor }}; font-weight: 600; border-bottom: 1px solid #f1f5f9;">{{ $d['percent'] }}%</td>
</tr>
@endforeach
</table>
@endif
<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px auto;">
<tr>
<td style="border-radius: 8px; background-color: #dc2626;">
<a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">Upgrade Package</a>
</td>
</tr>
</table>

If you have questions about upgrade options, feel free to reach out.

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
