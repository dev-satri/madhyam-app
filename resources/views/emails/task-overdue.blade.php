@component ('mail::message')
    # Overdue: {{ $task->title }}
    Hi {{ $recipient->name }},

This {{ $kindLower }} is **{{ $daysOverdue }} {{ $daysOverdue === 1 ? 'day' : 'days' }} past its due date**. Please close it out or update the status so we know where things stand.
    @component ('mail::panel')
        **Title**
        {{ $task->title }}
        @if ($task->due_date)
            **Was due**
            {{ $task->due_date->format('l, M d, Y') }}
        @endif
        **Current status**
        {{ ucfirst(str_replace('-', ' ', $task->status ?? 'todo')) }}
        **Priority**
        {{ ucfirst($task->priority ?? 'normal') }}
        @if ($task->client)
            **Client**
            {{ $task->client->name }}
        @endif
    @endcomponent

    @component ('mail::button', ['url' => $url, 'color' => 'error'])
        Open {{ $kindLower }}
    @endcomponent
    If the {{ $kindLower }} is blocked, drop a comment on it so your manager can help unblock it.

Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
