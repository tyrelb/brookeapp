<x-layouts.portal :title="$client->first_name.' — Fitness Wallet'" :heading="$trainer->displayName()" :subheading="'Fitness Wallet for '.$client->full_name">
    @php($isMonthly = $client->isOnMonthlyPlan())
    @php($base = route('portal.wallet', ['token' => $token]))

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
                    @elseif ($sessionsLeft !== null)
                        Prepaid credit, GST included · about {{ $sessionsLeft }} {{ $client->isOnFamilyPlan() ? 'family' : 'single' }} {{ Str::plural('session', $sessionsLeft) }} left.
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

    @if ($invoices->isNotEmpty())
        <section class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-700/60 dark:bg-amber-950/30">
            <flux:heading>{{ $invoices->count() === 1 ? 'Payment requested' : 'Payments requested' }}</flux:heading>
            <flux:text class="mt-1 text-sm">{{ $trainer->displayName() }} has asked for the following. Please get in touch once it's sent so it can be marked off.</flux:text>

            <div class="mt-3 space-y-3">
                @foreach ($invoices as $invoice)
                    <div class="rounded-lg border border-amber-200 bg-white p-3 text-sm dark:border-amber-800/60 dark:bg-zinc-900">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <span class="font-medium">{{ $invoice->number }}</span>
                            <span class="text-lg font-semibold tabular-nums">{{ money($invoice->outstandingAmount()) }}</span>
                        </div>
                        <div class="mt-1 text-zinc-600 dark:text-zinc-400">
                            {{ collect($invoice->lines)->pluck('description')->join(', ') }}
                            @if ((float) $invoice->gst_amount > 0)
                                <span class="text-zinc-500">(includes {{ money($invoice->gst_amount) }} GST)</span>
                            @endif
                        </div>
                        <div class="mt-1 text-xs text-zinc-500">
                            Issued {{ $invoice->issued_on->format('M j, Y') }}
                            @if ($invoice->due_on)
                                · due {{ $invoice->due_on->format('M j, Y') }}
                                @if ($invoice->due_on->isBefore(today()))
                                    <flux:badge size="sm" color="red" class="ml-1">Overdue</flux:badge>
                                @endif
                            @endif
                            @if ($invoice->paidAmount() > 0)
                                · {{ money($invoice->paidAmount()) }} of {{ money($invoice->total) }} received
                            @endif
                        </div>
                        @if ($invoice->message)
                            <div class="mt-2 whitespace-pre-line text-zinc-600 dark:text-zinc-400">{{ $invoice->message }}</div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-3 text-sm">
                <span class="text-zinc-500">How to pay:</span>
                {{ collect($trainer->enabledPaymentMethods())->map->label()->join(', ') }}
                @if ($trainer->etransfer_email && $trainer->acceptsPaymentMethod(\App\Enums\PaymentMethod::ETransfer))
                    · e-Transfer to <span class="font-medium">{{ $trainer->etransfer_email }}</span>
                @endif
            </div>
        </section>
    @endif

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

    {{-- View switch --}}
    <div class="mt-8 flex items-center justify-between gap-3">
        <flux:heading size="lg">Your sessions</flux:heading>
        <div class="inline-flex rounded-lg border border-zinc-200 p-0.5 text-sm dark:border-zinc-700">
            <a href="{{ $base }}?view=list" class="rounded-md px-3 py-1 {{ $view === 'list' ? 'bg-[var(--color-accent)] text-white' : 'text-zinc-600 dark:text-zinc-300' }}">List</a>
            <a href="{{ $base }}?view=calendar" class="rounded-md px-3 py-1 {{ $view === 'calendar' ? 'bg-[var(--color-accent)] text-white' : 'text-zinc-600 dark:text-zinc-300' }}">Calendar</a>
        </div>
    </div>

    @if ($view === 'calendar')
        <section class="mt-3">
            <div class="mb-2 flex items-center justify-between">
                <a href="{{ $base }}?view=calendar&month={{ $anchor->subMonth()->format('Y-m') }}" class="rounded-md border border-zinc-200 px-2 py-1 text-sm dark:border-zinc-700">‹ {{ $anchor->subMonth()->format('M') }}</a>
                <div class="text-sm font-medium">{{ $anchor->format('F Y') }} <span class="text-zinc-500">· {{ $monthCount }} {{ Str::plural('session', $monthCount) }}</span></div>
                <a href="{{ $base }}?view=calendar&month={{ $anchor->addMonth()->format('Y-m') }}" class="rounded-md border border-zinc-200 px-2 py-1 text-sm dark:border-zinc-700">{{ $anchor->addMonth()->format('M') }} ›</a>
            </div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="grid min-w-[640px] grid-cols-7 border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow)<div class="px-2 py-1.5">{{ $dow }}</div>@endforeach
                </div>
                @php($today = today()->toDateString())
                @foreach ($weeks as $week)
                    <div class="grid min-w-[640px] grid-cols-7 border-b border-zinc-100 last:border-b-0 dark:border-zinc-700">
                        @foreach ($week as $day)
                            @php($key = $day->toDateString())
                            <div class="min-h-20 border-r border-zinc-100 p-1.5 last:border-r-0 dark:border-zinc-700 {{ $day->month !== $anchor->month ? 'bg-zinc-50/60 dark:bg-zinc-900/40' : '' }}">
                                <span class="inline-flex size-5 items-center justify-center rounded-full text-xs {{ $key === $today ? 'bg-[var(--color-accent)] font-semibold text-white' : ($day->month !== $anchor->month ? 'text-zinc-400' : 'text-zinc-600 dark:text-zinc-300') }}">{{ $day->day }}</span>
                                @foreach ($sessionsByDay->get($key, collect()) as $session)
                                    @php($status = $session->status->value)
                                    @php($missed = $status === 'completed' && ! $session->myAttendance->attended)
                                    <div class="mt-1 truncate rounded px-1 py-0.5 text-xs {{ $missed ? 'bg-zinc-100 text-zinc-500 line-through dark:bg-zinc-700' : ($status === 'completed' ? 'bg-green-50 text-green-800 dark:bg-green-900/40 dark:text-green-200' : ($status === 'cancelled' ? 'bg-zinc-100 text-zinc-500 line-through dark:bg-zinc-700' : 'bg-[var(--color-accent)]/10 text-[var(--color-accent-content)] dark:text-zinc-100')) }}" title="{{ $session->service->name }}">
                                        <span class="font-medium">{{ $session->starts_at->format('g:i') }}</span> {{ $session->service->name }}
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex flex-wrap gap-4 text-xs text-zinc-500">
                <span><span class="inline-block size-2.5 rounded-sm bg-[var(--color-accent)]/30 align-middle"></span> Booked</span>
                <span><span class="inline-block size-2.5 rounded-sm bg-green-200 align-middle"></span> Completed</span>
                <span><span class="inline-block size-2.5 rounded-sm bg-zinc-200 align-middle"></span> Cancelled or missed</span>
            </div>
        </section>
    @else
        <section class="mt-3">
            <div class="mb-2 text-sm text-zinc-500">
                @if ($upcomingTotal === 0)
                    Nothing booked yet.
                @else
                    {{ $upcomingTotal }} upcoming {{ Str::plural('session', $upcomingTotal) }}@if ($lastUpcoming) through {{ $lastUpcoming->starts_at->format('M j, Y') }}@endif.
                @endif
            </div>
            @if ($upcoming->isNotEmpty())
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    @foreach ($upcoming as $attendance)
                        @php($session = $attendance->trainingSession)
                        <div class="flex items-center justify-between gap-3 border-t border-zinc-100 px-4 py-3 text-sm first:border-t-0 dark:border-zinc-700">
                            <div>
                                <div class="font-medium">{{ $session->starts_at->format('l, F j') }} · {{ $session->starts_at->format('g:i a') }}</div>
                                <div class="text-zinc-500">{{ $session->service->name }} · {{ $session->duration_minutes }} min @if ($session->attendees->count() > 1) · with {{ $session->attendees->pluck('client')->reject(fn ($c) => $c->is($client))->pluck('first_name')->join(', ') }}@endif @if ($session->series && $loop->first) · {{ strtolower($session->series->describe()) }}@endif</div>
                            </div>
                            <flux:badge size="sm" color="blue">Booked</flux:badge>
                        </div>
                    @endforeach
                </div>
                @if ($upcoming->hasPages())
                    <div class="mt-2 flex justify-between text-sm">
                        <span>@if ($upcoming->onFirstPage())&nbsp;@else<a class="underline" href="{{ $upcoming->previousPageUrl() }}#sessions">‹ Previous</a>@endif</span>
                        <span>@if ($upcoming->hasMorePages())<a class="underline" href="{{ $upcoming->nextPageUrl() }}#sessions">More upcoming ›</a>@endif</span>
                    </div>
                @endif
            @endif
        </section>

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
                @if ($transactions->hasPages())
                    <div class="mt-2 flex justify-between text-sm">
                        <span>@unless ($transactions->onFirstPage())<a class="underline" href="{{ $transactions->previousPageUrl() }}">‹ Newer</a>@endunless</span>
                        <span>@if ($transactions->hasMorePages())<a class="underline" href="{{ $transactions->nextPageUrl() }}">Older ›</a>@endif</span>
                    </div>
                @endif
                <div class="mt-1 text-xs text-zinc-500">Deposits include GST; sessions show the GST portion charged.</div>
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
                                        @if (! $attendance->attended && $session->isCompleted()) <flux:badge size="sm" color="zinc">Missed</flux:badge>
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
                @if ($history->hasPages())
                    <div class="mt-2 flex justify-between text-sm">
                        <span>@unless ($history->onFirstPage())<a class="underline" href="{{ $history->previousPageUrl() }}">‹ Newer</a>@endunless</span>
                        <span>@if ($history->hasMorePages())<a class="underline" href="{{ $history->nextPageUrl() }}">Older ›</a>@endif</span>
                    </div>
                @endif
            @endif
        </section>
    @endif
</x-layouts.portal>
