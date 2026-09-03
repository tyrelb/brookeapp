<div class="space-y-6">
    <x-page-header title="Gym usage report" :subtitle="($currentGym ? $currentGym->name.' · ' : '').$label">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2 print:hidden">
                @if ($gyms->count() > 1)
                    <div class="w-48"><flux:select wire:model.live="gym">
                        @foreach ($gyms as $g)
                            <flux:select.option value="{{ $g->id }}">{{ $g->name }}{{ $g->active ? '' : ' (inactive)' }}</flux:select.option>
                        @endforeach
                    </flux:select></div>
                @endif
                <flux:button icon="chevron-left" wire:click="previous" />
                <div class="w-44"><flux:input wire:model.live="month" type="month" /></div>
                <flux:button icon="chevron-right" wire:click="next" />
                @if ($currentGym)
                    <flux:button icon="arrow-down-tray" wire:click="exportCsv">CSV</flux:button>
                    <flux:button icon="printer" onclick="window.print()">Print</flux:button>
                    @if ($finalizedReport)
                        <flux:button icon="lock-open" wire:click="reopen" wire:confirm="Reopen {{ $label }} for {{ $currentGym->name }}? Rows become live again and can be changed.">Reopen</flux:button>
                    @else
                        <flux:button icon="lock-closed" variant="primary" wire:click="finalize" wire:confirm="Finalize {{ $label }} for {{ $currentGym->name }}? The rows and totals will be locked as they are now.">Finalize</flux:button>
                    @endif
                @endif
            </div>
        </x-slot:actions>
    </x-page-header>

    @if (! $currentGym)
        <x-empty-state title="No gym set up yet" description="Add the gym you train at, with its monthly rate and per-session rates, and this report will show what you owe each month.">
            <flux:button :href="route('settings.gyms')" variant="primary" wire:navigate>Add a gym</flux:button>
        </x-empty-state>
    @else
        @php($s = $report['summary'])

        <div class="flex flex-wrap items-center gap-2 text-sm">
            @if ($finalizedReport)
                <flux:badge color="green" icon="lock-closed">Finalized {{ $finalizedReport->finalized_at->format('M j, Y g:i a') }}</flux:badge>
                <span class="text-zinc-500">Showing the locked snapshot. Reopen to change which sessions count.</span>
            @else
                <flux:badge color="amber">Draft</flux:badge>
                <span class="text-zinc-500">Untick any session the gym shouldn't charge for, then finalize when it matches their invoice.</span>
            @endif
        </div>

        @if (! $finalizedReport && $report['unassigned'] > 0)
            <flux:callout icon="exclamation-triangle" variant="warning" class="print:hidden">
                <flux:callout.heading>{{ $report['unassigned'] }} completed {{ Str::plural('session', $report['unassigned']) }} in {{ $label }} {{ $report['unassigned'] === 1 ? 'has' : 'have' }} no gym</flux:callout.heading>
                <flux:callout.text>They're not counted here. Assign them all to {{ $currentGym->name }}, or set the gym on each session individually.</flux:callout.text>
                <x-slot:actions><flux:button size="sm" wire:click="assignUnassigned">Assign to {{ $currentGym->name }}</flux:button></x-slot:actions>
            </flux:callout>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat-card label="Sessions counted" :value="$s['sessions']" :hint="$s['sessions_excluded'] ? $s['sessions_excluded'].' excluded' : 'None excluded'" />
            <x-stat-card label="People trained" :value="$s['people']" hint="Attendees across counted sessions" />
            <x-stat-card label="Usage charges" :value="money($s['usage_subtotal'])" :hint="$s['monthly_fee'] !== null ? 'Monthly rate '.money($s['monthly_fee']) : 'No monthly rate'" />
            <x-stat-card label="Total owed to gym" :value="money($s['total'])" :hint="$report['gym']['charges_gst'] ? 'Includes GST '.money($s['gst']) : 'No GST charged'" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading>Summary by group size</flux:heading>
                <table class="mt-3 w-full text-sm">
                    <thead class="text-xs text-zinc-500">
                        <tr><th class="py-1 text-left font-normal">Group size</th><th class="py-1 text-right font-normal">Sessions</th><th class="py-1 text-right font-normal">Rate</th><th class="py-1 text-right font-normal">Amount</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($s['by_people'] as $size => $row)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-1.5">{{ $size }} {{ Str::plural('person', $size) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['sessions'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['rate'] === null ? '—' : money($row['rate']) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ money($row['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-sm text-zinc-500">No counted sessions this month.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading>Amount owed</flux:heading>
                <table class="mt-3 w-full text-sm">
                    <tbody>
                        <tr><td class="py-1.5">Usage charges ({{ $s['sessions'] }} {{ Str::plural('session', $s['sessions']) }})</td><td class="py-1.5 text-right tabular-nums">{{ money($s['usage_subtotal']) }}</td></tr>
                        @if ($s['monthly_fee'] !== null)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800"><td class="py-1.5">Monthly rate</td><td class="py-1.5 text-right tabular-nums">{{ money($s['monthly_fee']) }}</td></tr>
                        @endif
                        <tr class="border-t border-zinc-200 dark:border-zinc-700"><td class="py-1.5">Subtotal</td><td class="py-1.5 text-right tabular-nums">{{ money($s['subtotal']) }}</td></tr>
                        <tr class="border-t border-zinc-100 dark:border-zinc-800"><td class="py-1.5">GST {{ $report['gym']['charges_gst'] ? '('.number_format($report['gym']['gst_rate'], 2).'%)' : '(not charged)' }}</td><td class="py-1.5 text-right tabular-nums">{{ money($s['gst']) }}</td></tr>
                        <tr class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600"><td class="py-2">Total</td><td class="py-2 text-right tabular-nums">{{ money($s['total']) }}</td></tr>
                    </tbody>
                </table>
                <div class="mt-2 text-xs text-zinc-500">{{ \App\Enums\GymBillingModel::from($report['gym']['billing_model'])->label() }}. GST paid to the gym is an input tax credit on your GST return.</div>
            </section>
        </div>

        <section>
            <flux:heading size="lg" class="mb-3">Detail</flux:heading>
            @if (empty($report['rows']))
                <x-empty-state title="No completed sessions at this gym in {{ $label }}" />
            @else
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                <th class="w-10 px-3 py-2 text-left font-normal print:hidden">Count</th>
                                <th class="px-3 py-2 text-left font-normal">Date</th>
                                <th class="px-3 py-2 text-left font-normal">Time</th>
                                <th class="px-3 py-2 text-left font-normal">Session</th>
                                <th class="px-3 py-2 text-right font-normal"># of people</th>
                                <th class="px-3 py-2 text-right font-normal">$ for the session</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report['rows'] as $row)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800 {{ $row['billable'] ? '' : 'text-zinc-400 line-through print:hidden' }}" wire:key="row-{{ $row['session_id'] }}">
                                    <td class="px-3 py-2 print:hidden">
                                        <flux:checkbox :checked="$row['billable']" :disabled="(bool) $finalizedReport" wire:click="toggleRow({{ $row['session_id'] }})" />
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ \Carbon\Carbon::parse($row['date'])->format('D M j, Y') }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ \Carbon\Carbon::createFromFormat('H:i', $row['time'])->format('g:i a') }}</td>
                                    <td class="px-3 py-2">
                                        <a href="{{ route('sessions.show', $row['session_id']) }}" wire:navigate class="hover:underline">{{ $row['service'] }}</a>
                                        <div class="text-xs text-zinc-500">{{ implode(', ', $row['attendees']) }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['people'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['rate'] === null ? '—' : money($row['rate']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600">
                                <td class="px-3 py-2 print:hidden"></td>
                                <td class="px-3 py-2" colspan="3">Total · {{ $s['sessions'] }} {{ Str::plural('session', $s['sessions']) }}{{ $s['sessions_excluded'] ? ' ('.$s['sessions_excluded'].' excluded)' : '' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $s['people'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ money($s['usage_subtotal']) }}</td>
                            </tr>
                            @if ($s['monthly_fee'] !== null)
                                <tr><td class="print:hidden"></td><td class="px-3 py-1" colspan="4">Monthly rate</td><td class="px-3 py-1 text-right tabular-nums">{{ money($s['monthly_fee']) }}</td></tr>
                            @endif
                            @if ($report['gym']['charges_gst'])
                                <tr><td class="print:hidden"></td><td class="px-3 py-1" colspan="4">GST</td><td class="px-3 py-1 text-right tabular-nums">{{ money($s['gst']) }}</td></tr>
                            @endif
                            <tr class="font-semibold"><td class="print:hidden"></td><td class="px-3 py-2" colspan="4">Total owed to {{ $currentGym->name }}</td><td class="px-3 py-2 text-right tabular-nums">{{ money($s['total']) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
