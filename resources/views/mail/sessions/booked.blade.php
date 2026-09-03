<x-mail::message>
# {{ $isUpdate ? 'Your session has been updated' : 'Your session is booked' }}

Hi {{ $client->first_name }},

@if ($isUpdate)
{{ $trainer->displayName() }} has changed your training session. Here are the new details:
@else
{{ $trainer->displayName() }} has booked you in for a training session:
@endif

<x-mail::panel>
**{{ $session->service->name }}**<br>
{{ $session->starts_at->format('l, F j, Y') }}<br>
{{ $session->starts_at->format('g:i a') }} – {{ $session->endsAt()->format('g:i a') }} ({{ $session->duration_minutes }} min)
@if ($others->isNotEmpty())
<br>Training with: {{ $others->join(', ') }}
@endif
</x-mail::panel>

A calendar invite is attached. Accept it to add this session to your calendar; if the time changes, an updated invite will replace it.

**Need to change or cancel?** Please contact {{ $trainer->displayName() }} directly rather than replying to the invite.
@if ($trainer->booking_instructions)

{{ $trainer->booking_instructions }}
@endif
@if ($trainer->phone)

Phone: {{ $trainer->phone }}
@endif

Email: {{ $trainer->email }}

<x-mail::button :url="$client->portalUrl()">View my Fitness Wallet</x-mail::button>

See you soon,<br>
{{ $trainer->displayName() }}
</x-mail::message>
