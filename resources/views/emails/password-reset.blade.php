@component ('mail::message')
    # Reset your {{ config('app.name', 'Madhyam') }} password

Hi {{ $name }},

We received a request to reset the password for the account below. If this was you, use the button to choose a new one.
    @component ('mail::panel')
        **Account**
        {{ $email }}
    @endcomponent

    @component ('mail::button', ['url' => $resetUrl, 'color' => 'primary'])
        Reset password
    @endcomponent
    This link will expire in **{{ $expiresInMinutes }} minutes**.

If you didn't request a password reset, you can safely ignore this email — your password will not change.

Thanks,
    {{ config('app.name', 'Madhyam') }} Team
    @component ('mail::subcopy')
        Having trouble with the button? Copy and paste this URL into your browser:
[{{ $resetUrl }}]({{ $resetUrl }})
    @endcomponent
@endcomponent
