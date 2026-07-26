{{-- prettier-ignore-start --}}
@component ('mail::message')
# Your Client Portal Access

Hi {{ $name }},

Your client portal account is ready. Use the credentials below to sign in for the first time.

<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
<tr>
<td style="background-color: #f8fafc; padding: 20px 24px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 6px 0; font-size: 14px; color: #64748b; vertical-align: top; width: 40%;">Login Email</td>
<td style="padding: 6px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right; word-break: break-all;">{{ $email }}</td>
</tr>
<tr>
<td style="padding: 6px 0; font-size: 14px; color: #64748b; vertical-align: top;">Temporary Password</td>
<td style="padding: 6px 0; font-size: 14px; color: #1e293b; font-weight: 700; text-align: right; font-family: 'Menlo', 'Consolas', 'Courier New', monospace; letter-spacing: 0.5px;">{{ $password }}</td>
</tr>
</table>
</td>
</tr>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px auto;">
<tr>
<td style="border-radius: 8px; background-color: #111827;">
<a href="{{ route('client.login') }}" target="_blank" style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">Login to Portal</a>
</td>
</tr>
</table>

<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0; border: 1px solid #fde68a; border-radius: 8px; overflow: hidden;">
<tr>
<td style="background-color: #fffbeb; padding: 16px 20px; font-size: 13px; color: #92400e; line-height: 1.6;">
<strong>Security reminder:</strong> please change your password after your first login from your profile settings. Never share this email or these credentials with anyone.
</td>
</tr>
</table>

## What you can do

<ul style="padding-left: 20px; margin: 12px 0; font-size: 14px; color: #334155; line-height: 1.7;">
<li><strong>Content Calendar</strong> — view and track all scheduled content</li>
<li><strong>Approvals</strong> — review and approve content before it goes live</li>
<li><strong>Workflows</strong> — monitor progress of your projects</li>
<li><strong>Invoices</strong> — view billing history and payment status</li>
<li><strong>Complaints</strong> — submit and track support requests</li>
</ul>

If you have trouble signing in, reply to this email and our team will help you right away.

Thanks,<br>
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
{{-- prettier-ignore-end --}}
