{{-- prettier-ignore-start --}}
@component ('mail::message')
# Welcome to {{ config('app.name', 'Madhyam') }}, {{ $name }}!

Your account has been created. Use the credentials below to sign in for the first time.

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
<tr>
<td style="padding: 6px 0; font-size: 14px; color: #64748b; vertical-align: top;">Role</td>
<td style="padding: 6px 0; font-size: 14px; color: #1e293b; font-weight: 600; text-align: right;">{{ ucfirst(str_replace('-', ' ', $role)) }}</td>
</tr>
</table>
</td>
</tr>
</table>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px auto;">
<tr>
<td style="border-radius: 8px; background-color: #111827;">
<a href="{{ config('app.url', 'http://localhost') }}" target="_blank" style="display: inline-block; padding: 12px 32px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">Login to Dashboard</a>
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

Thanks,<br>
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
{{-- prettier-ignore-end --}}
