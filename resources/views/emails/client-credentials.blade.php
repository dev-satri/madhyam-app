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

    @component ('mail::button', ['url' => config('app.url', 'http://localhost')])
        Login to Portal
    @endcomponent

    @component ('mail::subheading')
        What You Can Do
    @endcomponent

    <ul>
        <li><strong>Content Calendar</strong> — View and track all your scheduled content</li>
        <li><strong>Approvals</strong> — Review and approve content before it goes live</li>
        <li><strong>Workflows</strong> — Monitor the progress of your projects</li>
        <li><strong>Invoices</strong> — View your billing history and payment status</li>
        <li><strong>Complaints</strong> — Submit and track support requests</li>
    </ul>

    @component ('mail::subheading')
        Security Tip
    @endcomponent
    For your security, we recommend changing your password after your first login. You can do this from your profile
    settings. If you have any trouble accessing your account, please contact our support team. Welcome aboard!
    Thanks,<br
     />
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
