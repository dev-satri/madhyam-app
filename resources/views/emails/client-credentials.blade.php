@component ('mail::message')
    # Your Client Portal Access

Hi {{ $name }},

Your client portal account has been created. Here are your login credentials:
    @component ('mail::panel')
        **Login Email**
        {{ $email }}
        **Password**
        {{ $password }}
    @endcomponent

    @component ('mail::button', ['url' => config('app.url', 'http://localhost'), 'color' => 'primary'])
        Login to Portal
    @endcomponent
    ## What You Can Do

- **Content Calendar** — View and track all your scheduled content
- **Approvals** — Review and approve content before it goes live
- **Workflows** — Monitor the progress of your projects
- **Invoices** — View your billing history and payment status
- **Complaints** — Submit and track support requests

## Security Tip

For your security, we recommend changing your password after your first login. You can do this from your profile settings. If you have any trouble accessing your account, please contact our support team.

Welcome aboard!

Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
