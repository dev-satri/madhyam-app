{{-- prettier-ignore-start --}}
@component ('mail::message')
# Welcome, {{ $client->contact ?? $client->name }}

Thanks for choosing <strong>{{ config('app.name', 'Madhyam') }}</strong> as your creative partner. Here's a quick summary of your account — you'll receive your portal login in a separate email.

<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
<tr>
<td style="background-color: #f8fafc; padding: 20px 24px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Client</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $client->name }}</td>
</tr>
@if ($packageName)
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Package</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $packageName }}</td>
</tr>
@endif
@if ($client->amount)
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Monthly Investment</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">NPR {{ number_format((float) $client->amount, 2) }}</td>
</tr>
@endif
@if ($client->contract_start)
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Contract Start</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $client->contract_start->format('M d, Y') }}</td>
</tr>
@endif
@if ($client->contract_end)
<tr>
<td style="padding: 4px 0; font-size: 14px; color: #64748b;">Contract End</td>
<td style="padding: 4px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ $client->contract_end->format('M d, Y') }}</td>
</tr>
@endif
</table>
</td>
</tr>
</table>

@if (!empty($client->deliverables))
<p style="font-size: 13px; color: #64748b; margin: 20px 0 8px 0; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">What's included</p>

<p style="font-size: 14px; color: #334155; line-height: 1.6; margin: 0 0 16px 0;">{{ $client->deliverables }}</p>
@endif

## What's next

Your dedicated client portal is where the work happens — content approvals, workflow tracking, invoices, and support all in one place.

<ul style="padding-left: 20px; margin: 12px 0; font-size: 14px; color: #334155; line-height: 1.7;">
<li><strong>Content calendar</strong> — see what's scheduled and coming up</li>
<li><strong>Approvals</strong> — review and sign off on content before it publishes</li>
<li><strong>Workflows</strong> — track project progress in real time</li>
<li><strong>Invoices</strong> — view billing history and payment status</li>
<li><strong>Complaints</strong> — raise and follow up on support requests</li>
</ul>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px auto;">
<tr>
<td style="border-radius: 8px; background-color: #111827;">
<a href="{{ route('client.login') }}" target="_blank" style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">Go to Client Portal</a>
</td>
</tr>
</table>

Have questions? Reach out to your account manager or email <strong>{{ config('app.support_email', 'support@' . parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST)) }}</strong> — we're here to help.

Welcome aboard.

Thanks,<br>
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
{{-- prettier-ignore-end --}}
