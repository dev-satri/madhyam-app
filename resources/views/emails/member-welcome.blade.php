@component ('mail::message')
    # Welcome to {{ config('app.name', 'Madhyam') }}, {{ $name }}!

Your account has been created. Here are your login credentials:
    @component ('mail::panel')
        **Email:** {{ $email }}
        **Password:** `{{ $password }}`
**Role:** {{ ucfirst(str_replace('-', ' ', $role)) }}
    @endcomponent
    You can log in at [{{ config('app.url', 'http://localhost') }}]({{ config('app.url', 'http://localhost') }}).

For security, we recommend changing your password after your first login.

Thanks,
     />
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
