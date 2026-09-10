<div class="space-y-6">
    <x-page-header title="Monthly report" :subtitle="$report['label']">
        <x-slot:actions>
            <flux:button icon="chevron-left" wire:click="previous" />
            <div class="w-44"><flux:input wire:model.live="month" type="month" /></div>
            <flux:button icon="chevron-right" wire:click="next" />
            <flux:button :href="route('reports.annual', ['year' => substr($month, 0, 4)])" wire:navigate>Annual</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Sessions completed" :value="$report['sessions']['count']" :hint="$report['sessions']['attendances'].' attendances'" />
        <x-stat-card label="Revenue (before GST)" :value="money($report['revenue']['total'])" :hint="'Sessions '.money($report['revenue']['sessions']).' · Monthly fees '.money($report['revenue']['monthly_fees']).($report['revenue']['cover_fees'] > 0 ? ' · Gym cover '.money($report['revenue']['cover_fees']) : '')" />
        <x-stat-card label="GST charged" :value="money($report['revenue']['gst'])" :hint="'Revenue incl. GST '.money($report['revenue']['total_with_gst'])" />
        <x-stat-card label="Payments received" :value="money($report['payments']['net'])" :hint="$report['payments']['refunds'] < 0 ? 'After refunds of '.money(abs($report['payments']['refunds'])) : $report['payments']['count'].' payments'" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Sessions by service</flux:heading>
            <flux:subheading>Completed sessions, grouped by service and tier</flux:subheading>
            @if (empty($report['sessions']['by_service']))
                <flux:text class="mt-3 text-sm">No completed sessions this month.</flux:text>
            @else
                <table class="mt-3 w-full text-sm">
                    <thead class="text-xs text-zinc-500">
                        <tr><th class="py-1 text-left font-normal">Service</th><th class="py-1 text-right font-normal">Sessions</th><th class="py-1 text-right font-normal">People</th><th class="py-1 text-right font-normal">Revenue</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($report['sessions']['by_service'] as $name => $row)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-1.5">
                                    {{ $name }}
                                    <div class="text-xs text-zinc-500">
                                        @foreach ($row['tiers'] as $tier => $count){{ $count }} {{ $tier }}@if (! $loop->last), @endif @endforeach
                                    </div>
                                </td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['sessions'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['attendances'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ money($row['revenue']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-2 text-xs text-zinc-500">Revenue here counts pay-as-you-go charges and gym cover fees; monthly members' sessions are included in their fee.</div>
            @endif
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Money received</flux:heading>
            <flux:subheading>Cash basis: payments dated in this month</flux:subheading>
            <table class="mt-3 w-full text-sm">
                <tbody>
                    @foreach ($report['payments']['by_method'] as $method => $amount)
                        <tr class="border-t border-zinc-100 first:border-t-0 dark:border-zinc-800">
                            <td class="py-1.5">{{ \App\Enums\PaymentMethod::from($method)->label() }}</td>
                            <td class="py-1.5 text-right tabular-nums">{{ money($amount) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-zinc-200 font-medium dark:border-zinc-700">
                        <td class="py-1.5">Payments received</td>
                        <td class="py-1.5 text-right tabular-nums">{{ money($report['payments']['total']) }}</td>
                    </tr>
                    @if ($report['payments']['refunds'] < 0)
                        <tr class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="py-1.5">Refunds</td>
                            <td class="py-1.5 text-right tabular-nums"><x-money :amount="$report['payments']['refunds']" /></td>
                        </tr>
                        <tr class="border-t border-zinc-200 font-medium dark:border-zinc-700">
                            <td class="py-1.5">Net received</td>
                            <td class="py-1.5 text-right tabular-nums">{{ money($report['payments']['net']) }}</td>
                        </tr>
                    @endif
                    <tr class="border-t border-zinc-100 dark:border-zinc-800">
                        <td class="py-1.5 text-zinc-500">GST portion of net received (5/105)</td>
                        <td class="py-1.5 text-right tabular-nums text-zinc-500">{{ money($report['payments']['gst_embedded']) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>GST summary</flux:heading>
            @if (! $gstRegistered)
                <flux:text class="mt-3 text-sm">You're not registered for GST, so nothing is charged or tracked.</flux:text>
            @else
                <table class="mt-3 w-full text-sm">
                    <tbody>
                        <tr><td class="py-1.5">GST charged on sessions and fees (accrual basis)</td><td class="py-1.5 text-right tabular-nums font-medium">{{ money($report['revenue']['gst']) }}</td></tr>
                        @if ($report['revenue']['cover_gst'] > 0)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-1.5 text-zinc-500">…of which GST on gym cover fees (settled by credit against the gym's invoice, not cash)</td>
                                <td class="py-1.5 text-right tabular-nums text-zinc-500">{{ money($report['revenue']['cover_gst']) }}</td>
                            </tr>
                        @endif
                        <tr class="border-t border-zinc-100 dark:border-zinc-800"><td class="py-1.5">GST embedded in money received (cash basis)</td><td class="py-1.5 text-right tabular-nums font-medium">{{ money($report['payments']['gst_embedded']) }}</td></tr>
                    </tbody>
                </table>
                <flux:callout class="mt-3" icon="information-circle">
                    <flux:callout.text>Prepaid wallet deposits mean the two bases differ. Ask your accountant which one to remit on; both are tracked here and in the annual export.</flux:callout.text>
                </flux:callout>
            @endif
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Balances at month end</flux:heading>
            <flux:subheading>As of {{ \Carbon\Carbon::parse($report['to'])->format('M j, Y') }}</flux:subheading>
            <table class="mt-3 w-full text-sm">
                <tbody>
                    <tr><td class="py-1.5">Prepaid Fitness Wallet credit held (a liability)</td><td class="py-1.5 text-right tabular-nums font-medium">{{ money($report['balances']['prepaid']) }}</td></tr>
                    <tr class="border-t border-zinc-100 dark:border-zinc-800"><td class="py-1.5">Owing to you by clients</td><td class="py-1.5 text-right tabular-nums font-medium">{{ money(abs($report['balances']['owing'])) }}</td></tr>
                </tbody>
            </table>
        </section>
    </div>
</div>
