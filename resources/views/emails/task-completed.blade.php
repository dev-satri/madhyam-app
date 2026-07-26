@component ('mail::message')
    # {{ $kind }} completed: {{ $task->title }}
    Hi {{ $recipient->name }},
    {{ $actor?->name ?? 'A team member' }} just marked the following {{ $kindLower }} as **completed**.
    @component ('mail::panel')
        **Title**
        {{ $task->title }}
        @if ($task->due_date)
            **Due date**
            {{ $task->due_date->format('l, M d, Y') }}
        @endif
        @if ($task->client)
            **Client**
            {{ $task->client->name }}
        @endif
        **Priority**
        {{ ucfirst($task->priority ?? 'normal') }}
    @endcomponent

    @if ($task->submission_notes)
        **Submission notes**
        {{ $task->submission_notes }}
    @endif

    @component ('mail::button', ['url' => $url, 'color' => 'success'])
        Review {{ $kindLower }}
    @endcomponent
    Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
