<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:40px 20px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
  <tr><td style="background:#000;padding:24px 32px;">
    <h1 style="margin:0;color:#fff;font-size:20px;font-weight:700;">{{ config('app.name', 'Madhyam') }}</h1>
  </td></tr>
  <tr><td style="padding:32px;">
    <h2 style="margin:0 0 16px;color:#111;font-size:18px;">Files attached to: {{ $workflow->title }}</h2>
    <p style="margin:0 0 8px;color:#333;font-size:14px;">Hi {{ $recipient->name }},</p>
    <p style="margin:0 0 20px;color:#555;font-size:14px;">{{ $actor->name }} attached {{ count($attachments) }} file(s) to workflow "{{ $workflow->title }}".</p>
    <table width="100%" cellpadding="12" cellspacing="0" style="background:#f9fafb;border-radius:8px;border:1px solid #eee;">
      <tr><td style="font-size:13px;color:#555;"><strong>Files</strong><br>
        @foreach ($attachments as $att)
          - {{ $att['name'] ?? 'File' }}<br>
        @endforeach
      </td></tr>
    </table>
    <br>
    <a href="{{ $url }}" style="display:inline-block;padding:12px 28px;background:#000;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;font-size:14px;">View Workflow</a>
  </td></tr>
  <tr><td style="padding:16px 32px;background:#f9fafb;border-top:1px solid #eee;">
    <p style="margin:0;color:#999;font-size:12px;">Thanks,<br>{{ config('app.name', 'Madhyam') }} Team</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
