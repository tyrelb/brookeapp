<x-mail::message>
@if ($kind === 'cancelled')
# Your sessions have been cancelled
@elseif ($kind === 'updated')
# Your sessions have been updated
@else
# Your sessions are booked
@endif

Hi {{ $client->first_name }},

@if ($kind === 'cancelled')
{{ $trainer->displayName() }} has cancelled the following {{ $sessions->count() }} {{ Str::plural('session', $sessions->count()) }}. The attached file removes them from your calendar, and nothing has been charged.
@elseif ($kind === 'updated')
{{ $trainer->displayName() }} has changed the following {{ $sessions->count() }} {{ Str::plural('session', $sessions->count()) }}. The attached invite updates them in your calendar.
@else
{{ $trainer->displayName() }} has booked you in for {{ $sessions->count() }} {{ Str::plural('session', $sessions->count()) }}@if ($series) ({{ strtolower($series->describe()) }})@endif. A calendar invite for all of them is attached.
@endif

<x-mail::panel>
**{{ $sessions->first()->service->name }}**@if ($sessions->first()->gym) · {{ $sessions->first()->gym->name }}@endif<br>
@foreach ($sessions as $session)
{{ $session->starts_at->format('D M j, Y') }} · {{ $session->starts_at->format('g:i a') }} – {{ $session->endsAt()->format('g:i a') }}<br>
@endforeach
</x-mail::panel>

@unless ($kind === 'cancelled')
Accept the invite to add every session to your calendar. If any single session changes later, you'll get an update for just that one.
@endunless

**Need to change or cancel?** Please contact {{ $trainer->displayName() }} directly.
@if ($trainer->booking_instructions)

{{ $trainer->booking_instructions }}
@endif
@if ($trainer->phone)

Phone: {{ $trainer->phone }}
@endif

Email: {{ $trainer->email }}

<x-mail::button :url="$client->portalUrl()">View my Fitness Wallet</x-mail::button>

{{ $trainer->displayName() }}
</x-mail::message>
