<x-mail::message>
# Payment received, thank you

Hi {{ $client->isOnFamilyPlan() ? 'there' : $client->first_name }},

{{ $trainer->displayName() }} has received **{{ money($payment->amount) }}**{{ $payment->payment_method ? ' by '.$payment->payment_method->label() : '' }} on {{ $payment->transacted_on->format('M j, Y') }}. Thank you!

@if ($paidInFull)
**{{ $invoice->number }} is now paid in full.**
@else
That goes towards {{ $invoice->number }}, which has **{{ money($outstanding) }}** still to pay.
@endif

<x-mail::table>
| | |
|:--|--:|
| {{ $invoice->number }} total | {{ money($invoice->total) }} |
| Received | {{ money($paid) }} |
| **{{ $paidInFull ? 'Left to pay' : 'Still to pay' }}** | **{{ money($outstanding) }}** |
</x-mail::table>

{{ $client->isOnMonthlyPlan() ? 'Account balance' : 'Fitness Wallet balance' }}: **{{ money($balance) }}**

<x-mail::button :url="$url">View my Fitness Wallet</x-mail::button>

Questions? Just reply to this email.

Thank you,<br>
{{ $trainer->displayName() }}
</x-mail::message>
