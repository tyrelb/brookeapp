<x-layouts.portal :title="$client->first_name.' — Fitness Wallet'" :heading="$trainer->displayName()" :subheading="'Fitness Wallet for '.$client->full_name">
    @php($isMonthly = $client->isOnMonthlyPlan())

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-sm text-zinc-500">{{ $isMonthly ? 'Account balance' : 'Fitness Wallet balance' }}</div>
            <div class="mt-1 text-3xl font-semibold tracking-tight"><x-money :amount="$balance" /></div>
            <div class="mt-1 text-sm text-zinc-500">
                @if ($isMonthly)
                    @if ($balance < 0) Amount owing on your {{ $client->plan->name }} membership. @else Your membership is paid up. @endif
                @else
                    @if ($balance < 0)
                        Your wallet is overdrawn; please top up before your next session.
                    @elseif ($singleRate)
                        Prepaid credit, GST included · about {{ floor($balance / $singleRate) }} single {{ Str::plural('session', (int) floor($balance / $singleRate)) }} left.
                    @else
                        Prepaid credit, GST included.
                    @endif
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-zinc-500">Your plan</div>
            <div class="mt-1 font-medium">{{ $client->plan?->name ?? 'No plan assigned yet' }}</div>
            @if ($isMonthly)
                <div class="text-zinc-500">{{ money($client->plan->monthly_fee) }} + GST per month · sessions included</div>
            @elseif ($client->plan)
                <div class="text-zinc-500">Pay-as-you-go · each session is deducted from your wallet</div>
            @endif
            <div class="mt-3 text-zinc-500">How to pay</div>
            <div class="mt-1">
                {{ collect($trainer->enabledPaymentMethods())->map->label()->join(', ') }}
                @if ($trainer->etransfer_email && $trainer->acceptsPaymentMethod(\App\Enums\PaymentMethod::ETransfer))
                    <div class="text-zinc-500">e-Transfer to <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $trainer->etransfer_email }}</span></div>
                @endif
            </div>
        </div>
    </div>

    <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading>Book or change a session</flux:heading>
        <flux:text class="mt-2 text-sm">Only {{ $trainer->displayName() }} can book, move or cancel sessions. Please get in touch directly:</flux:text>
        @if ($trainer->booking_instructions)
            <flux:text class="mt-2 whitespace-pre-line text-sm">{{ $trainer->booking_instructions }}</flux:text>
        @endif
        <div class="mt-2 text-sm">
            @if ($trainer->phone)<div>Phone: <a class="underline" href="tel:{{ preg_replace('/[^0-9+]/', '', $trainer->phone) }}">{{ $trainer->phone }}</a></div>@endif
            <div>Email: <a class="underline" href="mailto:{{ $trainer->email }}">{{ $trainer->email }}</a></div>
        </div>
    </section>

    @if ($upcoming->isNotEmpty())
        <section class="mt-6">
            <flux:heading class="mb-2">Upcoming sessions</flux:heading>
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                @foreach ($upcoming as $attendance)
                    @php($session = $attendance->trainingSession)
                    <div class="flex items-center justify-between gap-3 border-t border-zinc-100 px-4 py-3 text-sm first:border-t-0 dark:border-zinc-700">
                        <div>
                            <div class="font-medium">{{ $session->starts_at->format('l, F j') }} · {{ $session->starts_at->format('g:i a') }}</div>
                            <div class="text-zinc-500">{{ $session->service->name }} · {{ $session->duration_minutes }} min @if ($session->attendees->count() > 1) · with {{ $session->attendees->pluck('client')->reject(fn ($c) => $c->is($client))->pluck('first_name')->join(', ') }}@endif</div>
                        </div>
                        <flux:badge size="sm" color="blue">Booked</flux:badge>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mt-6">
        <flux:heading class="mb-2">Activity</flux:heading>
        @if ($transactions->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700">No deposits or sessions yet.</div>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-xs text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-2 font-normal">Date</th>
                            <th class="px-4 py-2 font-normal">Description</th>
                            <th class="px-4 py-2 text-right font-normal">GST</th>
                            <th class="px-4 py-2 text-right font-normal">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transactions as $tx)
                            <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                <td class="whitespace-nowrap px-4 py-2">{{ $tx->transacted_on->format('M j, Y') }}</td>
                                <td class="px-4 py-2">
                                    {{ $tx->type->label() }}@if ($tx->description) · {{ $tx->description }}@endif
                                    @if ($tx->payment_method) <span class="text-zinc-500">({{ $tx->payment_method->label() }})</span>@endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-zinc-500">{{ (float) $tx->gst_amount > 0 ? money($tx->gst_amount) : '' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-medium"><x-money :amount="$tx->amount" signed /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-1 text-xs text-zinc-500">Most recent 100 entries. Deposits include GST; sessions show the GST portion charged.</div>
        @endif
    </section>

    <section class="mt-6">
        <flux:heading class="mb-2">Training history</flux:heading>
        @if ($history->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700">No sessions yet.</div>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-xs text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-2 font-normal">Date</th>
                            <th class="px-4 py-2 font-normal">Session</th>
                            <th class="px-4 py-2 font-normal">Status</th>
                            <th class="px-4 py-2 text-right font-normal">Charge</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $attendance)
                            @php($session = $attendance->trainingSession)
                            <tr class="border-t border-zinc-100 dark:border-zinc-700">
                                <td class="whitespace-nowrap px-4 py-2">{{ $session->starts_at->format('D M j, Y') }}</td>
                                <td class="px-4 py-2">{{ $session->service->name }} <span class="text-zinc-500">· {{ \App\Models\Plan::headcountLabel($session->headcount()) }}</span></td>
                                <td class="px-4 py-2">
                                    @if (! $attendance->attended) <flux:badge size="sm" color="zinc">Missed</flux:badge>
                                    @else <flux:badge size="sm" :color="$session->status->color()">{{ $session->status->label() }}</flux:badge> @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    @if ($session->isCompleted() && $attendance->attended)
                                        {{ (float) $attendance->total > 0 ? money($attendance->total) : 'Included' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.portal>
