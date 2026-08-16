@component('mail::message')
# {{ $headline }}: {{ $task->title }}

Hi {{ $recipient->name }},

@if ($when === 'today')
This {{ $kindLower }} is due **today**. Please make sure it's wrapped up before end of day.
@else
This {{ $kindLower }} is due **tomorrow**. Heads up so you can plan your day.
@endif

@component('mail::panel')
**Title**
{{ $task->title }}
@if ($task->due_date)

**Due date**
{{ $task->due_date->format('l, M d, Y') }}
@endif

**Priority**
{{ ucfirst($task->priority ?? 'normal') }}

**Current status**
{{ ucfirst(str_replace('-', ' ', $task->status ?? 'todo')) }}
@if ($task->client)

**Client**
{{ $task->client->name }}
@endif
@endcomponent

@component('mail::button', ['url' => $url, 'color' => 'primary'])
Open {{ $kindLower }}
@endcomponent

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
