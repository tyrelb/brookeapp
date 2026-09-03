<x-mail::message>
# Your Fitness Wallet

Hi {{ $client->first_name }},

Here's your private link to see your balance, deposits, sessions and upcoming bookings with {{ $trainer->displayName() }}. No password needed; just open it any time.

<x-mail::button :url="$url">Open my Fitness Wallet</x-mail::button>

@if ($client->isOnMonthlyPlan())
@if ($balance < 0)
Current balance owing: **{{ money(abs($balance)) }}**.
@else
Your membership is paid up.
@endif
@else
Current balance: **{{ money($balance) }}**.
@endif

This link is yours alone, so please don't forward it. If you think someone else has it, let {{ $trainer->displayName() }} know and a new one can be sent.

**To book or change a session** please contact {{ $trainer->displayName() }} directly.
@if ($trainer->booking_instructions)

{{ $trainer->booking_instructions }}
@endif
@if ($trainer->phone)

Phone: {{ $trainer->phone }}
@endif

Email: {{ $trainer->email }}

{{ $trainer->displayName() }}
</x-mail::message>
