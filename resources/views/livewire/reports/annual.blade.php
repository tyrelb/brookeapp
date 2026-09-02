<div class="space-y-6">
    <x-page-header title="Annual report" :subtitle="'Tax year '.$report['year']">
        <x-slot:actions>
            <flux:button icon="chevron-left" wire:click="previous" />
            <flux:input wire:model.live="year" type="number" min="2000" max="2100" class="w-28" />
            <flux:button icon="chevron-right" wire:click="next" />
            <flux:button icon="arrow-down-tray" variant="primary" wire:click="exportCsv">Export CSV</flux:button>
        </x-slot:actions>
    </x-page-header>

    @php($t = $report['totals'])
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Sessions completed" :value="$t['sessions']['count']" :hint="$t['sessions']['attendances'].' attendances'" />
        <x-stat-card label="Revenue (before GST)" :value="money($t['revenue']['total'])" :hint="'Sessions '.money($t['revenue']['sessions']).' · Fees '.money($t['revenue']['monthly_fees'])" />
        <x-stat-card label="GST charged" :value="money($t['revenue']['gst'])" :hint="'GST in money received '.money($t['payments']['gst_embedded'])" />
        <x-stat-card label="Net money received" :value="money($t['payments']['net'])" :hint="'Prepaid credit held at year end '.money($t['balances']['prepaid'])" />
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="px-3 py-2 text-left font-normal">Month</th>
                    <th class="px-3 py-2 text-right font-normal">Sessions</th>
                    <th class="px-3 py-2 text-right font-normal">Session revenue</th>
                    <th class="px-3 py-2 text-right font-normal">Monthly fees</th>
                    <th class="px-3 py-2 text-right font-normal">Revenue</th>
                    <th class="px-3 py-2 text-right font-normal">GST charged</th>
                    @foreach ($methods as $method)
                        <th class="px-3 py-2 text-right font-normal">{{ $method->label() }}</th>
                    @endforeach
                    <th class="px-3 py-2 text-right font-normal">Refunds</th>
                    <th class="px-3 py-2 text-right font-normal">Net received</th>
                    <th class="px-3 py-2 text-right font-normal">Prepaid held</th>
                    <th class="px-3 py-2 text-right font-normal">Owing</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['months'] as $m)
                    <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="m-{{ $m['from'] }}">
                        <td class="px-3 py-1.5"><flux:link :href="route('reports.monthly', ['month' => substr($m['from'], 0, 7)])" wire:navigate>{{ \Carbon\Carbon::parse($m['from'])->format('M') }}</flux:link></td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $m['sessions']['count'] }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ money($m['revenue']['sessions']) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ money($m['revenue']['monthly_fees']) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums font-medium">{{ money($m['revenue']['total']) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ money($m['revenue']['gst']) }}</td>
                        @foreach ($methods as $method)
                            <td class="px-3 py-1.5 text-right tabular-nums">{{ money($m['payments']['by_method'][$method->value]) }}</td>
                        @endforeach
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ money($m['payments']['refunds']) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums font-medium">{{ money($m['payments']['net']) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ money($m['balances']['prepaid']) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ money(abs($m['balances']['owing'])) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600">
                    <td class="px-3 py-2">Total {{ $report['year'] }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $t['sessions']['count'] }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money($t['revenue']['sessions']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money($t['revenue']['monthly_fees']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money($t['revenue']['total']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money($t['revenue']['gst']) }}</td>
                    @foreach ($methods as $method)
                        <td class="px-3 py-2 text-right tabular-nums">{{ money($t['payments']['by_method'][$method->value]) }}</td>
                    @endforeach
                    <td class="px-3 py-2 text-right tabular-nums">{{ money($t['payments']['refunds']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money($t['payments']['net']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money($t['balances']['prepaid']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money(abs($t['balances']['owing'])) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <flux:callout icon="information-circle">
        <flux:callout.heading>Reading this report</flux:callout.heading>
        <flux:callout.text>
            Revenue is what you charged (sessions deducted from Fitness Wallets plus monthly fees), before GST, on the date of the session or fee.
            Money received is what actually came in, by payment method. Prepaid held is client credit you're holding at month end; owing is fees not yet paid.
            The CSV export includes every column for your accountant.
        </flux:callout.text>
    </flux:callout>
</div>
