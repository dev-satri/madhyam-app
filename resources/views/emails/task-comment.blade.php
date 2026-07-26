@component ('mail::message')
    # New comment on {{ $task->title }}
    Hi {{ $recipient->name }},
    {{ $actor?->name ?? 'Someone' }} left a comment on this {{ $kindLower }}:
    @component ('mail::panel')
        {{ $comment->text }}
    @endcomponent

    @component ('mail::button', ['url' => $url, 'color' => 'primary'])
        Open {{ $kindLower }} & reply
    @endcomponent
    Thanks,
    {{ config('app.name', 'Madhyam') }} Team
@endcomponent
