<x-mail::message>
# Thanks for training today

Hi {{ $client->first_name }},

Here's a summary of your session with {{ $trainer->displayName() }}.

<x-mail::panel>
**{{ $session->service->name }}** ({{ $tier }})<br>
{{ $session->starts_at->format('l, F j, Y') }} at {{ $session->starts_at->format('g:i a') }}
</x-mail::panel>

@if ((float) $attendee->total > 0)
<x-mail::table>
| | |
|:--|--:|
| Session ({{ $tier }} rate) | {{ money($attendee->subtotal) }} |
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
