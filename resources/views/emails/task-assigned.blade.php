@component('mail::message')
# {{ $kind }} assigned to you

Hi {{ $recipient->name }},

{{ $actor?->name ?? 'A team member' }} assigned you a new {{ $kindLower }}. Full details below.

@component('mail::panel')
**Title**
{{ $task->title }}

**Priority**
{{ ucfirst($task->priority ?? 'normal') }}
@if ($task->due_date)

**Due date**
{{ $task->due_date->format('l, M d, Y') }}
@endif
@if ($task->client)

**Client**
{{ $task->client->name }}
@endif
@if ($task->location)

**Location**
{{ $task->location }}
@endif
@endcomponent

@if ($task->description)
**Description**
{{ $task->description }}
@endif

@component('mail::button', ['url' => $url, 'color' => 'primary'])
Open {{ $kindLower }}
@endcomponent

Thanks,
{{ config('app.name', 'Madhyam') }} Team
@endcomponent
