@component ('mail::message')
    # Welcome to {{ config('app.name', 'Madhyam') }}, {{ $name }}!

Your account has been created. Here are your login credentials:
    @component ('mail::panel')
        **Login Email**
        {{ $email }}
        **Password**
        {{ $password }}
        **Role**
        {{ ucfirst(str_replace('-', ' ', $role)) }}
    @endcomponent

    @component ('mail::button', ['url' => config('app.url', 'http://localhost')])
        Login to Dashboard
    @endcomponent
    For security, we recommend changing your password after your first login. Thanks,<br
     />
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
