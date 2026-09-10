<x-mail::message>
# Thanks for training today

Hi {{ $client->isOnFamilyPlan() ? 'there' : $client->first_name }},

Here's a summary of your session with {{ $trainer->displayName() }}.

<x-mail::panel>
**{{ $session->service->name }}** ({{ $tier }})<br>
@if ($members->isNotEmpty())
Attending: {{ $members->pluck('member_name')->join(', ') }}<br>
@endif
{{ $session->starts_at->format('l, F j, Y') }} at {{ $session->starts_at->format('g:i a') }}
</x-mail::panel>

@if ((float) $attendee->total > 0)
<x-mail::table>
| | |
|:--|--:|
@if ($members->isNotEmpty())
@foreach ($members as $member)
| {{ $member->member_name }} ({{ $rateTier }} rate) | {{ money($member->subtotal) }} |
@endforeach
@else
| Session ({{ $rateTier }} rate) | {{ money($attendee->subtotal) }} |
@endif
@if ((float) $attendee->gst_amount > 0)
| GST | {{ money($attendee->gst_amount) }} |
@endif
| **Deducted from your Fitness Wallet** | **{{ money($attendee->total) }}** |
</x-mail::table>
@elseif ($isMonthly)
This session is included in your **{{ $client->plan->name }}** membership.
@else
No charge was applied for this session.
@endif

@if ($isMonthly)
@if ($balance < 0)
**Balance owing on your account: {{ money(abs($balance)) }}.**
@else
Your membership is paid up. Thank you!
@endif
@else
@if ($balance < 0)
**Your Fitness Wallet is overdrawn by {{ money(abs($balance)) }}.** Please top up before your next session.
@else
**Fitness Wallet balance: {{ money($balance) }}** remaining.
@endif
@endif

@if ($trainer->acceptsPaymentMethod(\App\Enums\PaymentMethod::ETransfer) && $trainer->etransfer_email)
You can top up by e-Transfer to **{{ $trainer->etransfer_email }}**{{ count($trainer->enabledPaymentMethods()) > 1 ? ', or by '.collect($trainer->enabledPaymentMethods())->reject(fn ($m) => $m === \App\Enums\PaymentMethod::ETransfer)->map->label()->join(' or ').' in person' : '' }}.
@endif

<x-mail::button :url="$client->portalUrl()">View my Fitness Wallet</x-mail::button>

Your wallet page shows every deposit and session, and this link is yours alone, so please don't forward it.

**To book your next session** please contact {{ $trainer->displayName() }} directly.
@if ($trainer->booking_instructions)

{{ $trainer->booking_instructions }}
@endif
@if ($trainer->phone)

Phone: {{ $trainer->phone }}
@endif

Email: {{ $trainer->email }}

See you next time,<br>
{{ $trainer->displayName() }}
</x-mail::message>
