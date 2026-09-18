<x-mail::message>
# {{ $client->isOnMonthlyPlan() ? 'Your membership fee' : 'Time to top up your Fitness Wallet' }}

Hi {{ $client->isOnFamilyPlan() ? 'there' : $client->first_name }},

@if ($client->isOnMonthlyPlan())
Here's your {{ $invoice->issued_on->format('F Y') }} membership fee for **{{ $client->plan?->name }}**.
@elseif ($balance < 0)
Your Fitness Wallet is overdrawn by **{{ money(abs($balance)) }}**. Here's a request to bring it back up so you're ready for your next session.
@elseif ($sessionsLeft !== null)
Your sessions are running low. You have **{{ money($balance) }}** in your Fitness Wallet — about {{ $sessionsLeft }} {{ Str::plural('session', $sessionsLeft) }} left. Would you like to reload?
@else
You have **{{ money($balance) }}** in your Fitness Wallet. Here's a request to top it up.
@endif

<x-mail::table>
| | |
|:--|--:|
@foreach ($invoice->lines as $line)
| {{ $line['description'] }} | {{ money($line['amount']) }} |
@endforeach
@if ((float) $invoice->gst_amount > 0)
| GST ({{ rtrim(rtrim(number_format((float) $invoice->gst_rate, 2), '0'), '.') }}%) | {{ money($invoice->gst_amount) }} |
@endif
| **Total** | **{{ money($invoice->total) }}** |
</x-mail::table>

{{-- An echo, not @if: PHP swallows the newline after a directive's closing tag, which
     would fold the trainer's message below into this line. Echoes keep theirs. --}}
{{ $invoice->number }} · issued {{ $invoice->issued_on->format('M j, Y') }}{!! $invoice->due_on ? ' · **due '.e($invoice->due_on->format('M j, Y')).'**' : '' !!}
@if ($invoice->message)

{{ $invoice->message }}
@endif

**How to pay:** {{ collect($trainer->enabledPaymentMethods())->map->label()->join(', ') }}.
@if ($trainer->acceptsPaymentMethod(\App\Enums\PaymentMethod::ETransfer) && $trainer->etransfer_email)
e-Transfer to **{{ $trainer->etransfer_email }}**.
@endif

<x-mail::button :url="$url">View my Fitness Wallet</x-mail::button>

Your wallet page shows this request along with every deposit and session. The link is yours alone, so please don't forward it.

**To book or change a session** please contact {{ $trainer->displayName() }} directly.
@if ($trainer->booking_instructions)

{{ $trainer->booking_instructions }}
@endif
@if ($trainer->phone)

Phone: {{ $trainer->phone }}
@endif

Email: {{ $trainer->email }}

Thank you,<br>
{{ $trainer->displayName() }}
</x-mail::message>
