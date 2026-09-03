<x-mail::message>
# Your session has been cancelled

Hi {{ $client->first_name }},

The following training session with {{ $trainer->displayName() }} has been cancelled:

<x-mail::panel>
**{{ $session->service->name }}**<br>
{{ $session->starts_at->format('l, F j, Y') }} at {{ $session->starts_at->format('g:i a') }}
</x-mail::panel>

The attached file removes it from your calendar. Nothing has been charged for this session.

To rebook, please contact {{ $trainer->displayName() }} directly.
@if ($trainer->booking_instructions)

{{ $trainer->booking_instructions }}
@endif
@if ($trainer->phone)

Phone: {{ $trainer->phone }}
@endif

Email: {{ $trainer->email }}

{{ $trainer->displayName() }}
</x-mail::message>
