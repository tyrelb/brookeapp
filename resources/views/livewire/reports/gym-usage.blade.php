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
        <x-empty-state title="No gym set up yet" description="Add the gym you train at, with its monthly rate and hourly usage rates, and this report will show what you owe each month.">
            <flux:button :href="route('settings.gyms')" variant="primary" wire:navigate>Add a gym</flux:button>
        </x-empty-state>
    @else
        @php($s = $report['summary'])
        {{-- Months finalized before cover sessions existed have none of these keys.
             Read them only through these variables. --}}
        @php($coverRows = $report['cover_rows'] ?? [])
        @php($coverSubtotal = $s['cover_subtotal'] ?? 0.0)
        @php($coverGst = $s['cover_gst'] ?? 0.0)
        @php($coverTotal = $s['cover_total'] ?? 0.0)
        @php($coverSessions = $s['cover_sessions'] ?? 0)
        @php($coverExcluded = $s['cover_sessions_excluded'] ?? 0)
        @php($coverPeople = $s['cover_people'] ?? 0)
        @php($coverGstRate = $s['cover_gst_rate'] ?? null)
        @php($netTotal = $s['net_total'] ?? $s['total'])

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
            @if ($netTotal < 0)
                <x-stat-card label="Owed to you by the gym" :value="money(abs($netTotal))" :hint="'After '.money($coverTotal).' of cover credit'" />
            @else
                <x-stat-card label="Total owed to gym" :value="money($netTotal)" :hint="$coverTotal > 0 ? 'After '.money($coverTotal).' of cover credit' : ($report['gym']['charges_gst'] ? 'Includes GST '.money($s['gst']) : 'No GST charged')" />
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading>Summary by group size</flux:heading>
                <flux:subheading class="mt-1">Charged by the hour, pro-rated to each session's length.</flux:subheading>
                <table class="mt-3 w-full text-sm">
                    <thead class="text-xs text-zinc-500">
                        <tr><th class="py-1 text-left font-normal">Group size</th><th class="py-1 text-right font-normal">Sessions</th><th class="py-1 text-right font-normal">Hours</th><th class="py-1 text-right font-normal">Rate/hour</th><th class="py-1 text-right font-normal">Amount</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($s['by_people'] as $size => $row)
                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-1.5">{{ $size }} {{ Str::plural('person', $size) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['sessions'] }}</td>
                                {{-- Months finalized before usage went hourly have no minutes recorded. --}}
                                <td class="py-1.5 text-right tabular-nums">{{ isset($row['minutes']) ? number_format($row['minutes'] / 60, 2) : '—' }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['rate'] === null ? '—' : money($row['rate']) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ money($row['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-3 text-sm text-zinc-500">No counted sessions this month.</td></tr>
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
                        <tr class="border-t-2 border-zinc-300 {{ $coverRows ? '' : 'font-semibold' }} dark:border-zinc-600"><td class="py-2">Total</td><td class="py-2 text-right tabular-nums">{{ money($s['total']) }}</td></tr>
                        @if ($coverRows)
                            <tr class="border-t border-zinc-100 text-emerald-700 dark:border-zinc-800 dark:text-emerald-400"><td class="py-1.5">Cover sessions ({{ $coverSessions }})</td><td class="py-1.5 text-right tabular-nums">&minus;{{ money($coverSubtotal) }}</td></tr>
                            @if ($coverGst > 0)
                                <tr class="text-emerald-700 dark:text-emerald-400"><td class="py-1.5">GST you charge {{ $coverGstRate === null ? '(mixed rates)' : '('.number_format($coverGstRate, 2).'%)' }}</td><td class="py-1.5 text-right tabular-nums">&minus;{{ money($coverGst) }}</td></tr>
                            @endif
                            <tr class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600"><td class="py-2">{{ $netTotal < 0 ? 'The gym owes you' : 'Net owed to gym' }}</td><td class="py-2 text-right tabular-nums">{{ money(abs($netTotal)) }}</td></tr>
                        @endif
                    </tbody>
                </table>
                <div class="mt-2 text-xs text-zinc-500">
                    {{ \App\Enums\GymBillingModel::from($report['gym']['billing_model'])->label() }}. GST paid to the gym is an input tax credit on your GST return.
                    @if ($coverRows) GST on cover fees is the opposite: tax you collected and must remit. @endif
                </div>
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
                                <th class="px-3 py-2 text-right font-normal">Length</th>
                                <th class="px-3 py-2 text-right font-normal">Rate/hour</th>
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
                                    <td class="px-3 py-2 text-right tabular-nums">{{ isset($row['minutes']) ? $row['minutes'].' min' : '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['rate'] === null ? '—' : money($row['rate']) }}</td>
                                    {{-- Snapshots taken before usage went hourly only have the flat rate, which was the charge. --}}
                                    @php($amount = $row['amount'] ?? $row['rate'])
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $amount === null ? '—' : money($amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600">
                                <td class="px-3 py-2 print:hidden"></td>
                                <td class="px-3 py-2" colspan="3">Total · {{ $s['sessions'] }} {{ Str::plural('session', $s['sessions']) }}{{ $s['sessions_excluded'] ? ' ('.$s['sessions_excluded'].' excluded)' : '' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $s['people'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ isset($s['minutes']) ? number_format($s['minutes'] / 60, 2).' hrs' : '—' }}</td>
                                <td class="px-3 py-2"></td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ money($s['usage_subtotal']) }}</td>
                            </tr>
                            @if ($s['monthly_fee'] !== null)
                                <tr><td class="print:hidden"></td><td class="px-3 py-1" colspan="6">Monthly rate</td><td class="px-3 py-1 text-right tabular-nums">{{ money($s['monthly_fee']) }}</td></tr>
                            @endif
                            @if ($report['gym']['charges_gst'])
                                <tr><td class="print:hidden"></td><td class="px-3 py-1" colspan="6">GST</td><td class="px-3 py-1 text-right tabular-nums">{{ money($s['gst']) }}</td></tr>
                            @endif
                            <tr class="font-semibold"><td class="print:hidden"></td><td class="px-3 py-2" colspan="6">{{ $coverRows ? 'Usage total' : 'Total owed to '.$currentGym->name }}</td><td class="px-3 py-2 text-right tabular-nums">{{ money($s['total']) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        @if ($coverRows)
            <section>
                <flux:heading size="lg" class="mb-1">Covering {{ $currentGym->name }}'s clients</flux:heading>
                <flux:subheading class="mb-3">Money the gym owes <strong>you</strong>, subtracted from what you owe it.</flux:subheading>

                <div class="overflow-x-auto rounded-xl border border-emerald-200 bg-white dark:border-emerald-900 dark:bg-zinc-900">
                    <table class="w-full text-sm">
                        <thead class="bg-emerald-50 text-xs text-zinc-500 dark:bg-emerald-950/40">
                            <tr>
                                <th class="w-10 px-3 py-2 text-left font-normal print:hidden">Count</th>
                                <th class="px-3 py-2 text-left font-normal">Date</th>
                                <th class="px-3 py-2 text-left font-normal">Time</th>
                                <th class="px-3 py-2 text-left font-normal">Who you trained</th>
                                <th class="px-3 py-2 text-right font-normal"># of people</th>
                                <th class="px-3 py-2 text-right font-normal">$ you charge</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($coverRows as $row)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800 {{ $row['billable'] ? '' : 'text-zinc-400 line-through print:hidden' }}" wire:key="cover-{{ $row['session_id'] }}">
                                    <td class="px-3 py-2 print:hidden">
                                        <flux:checkbox :checked="$row['billable']" :disabled="(bool) $finalizedReport" wire:click="toggleRow({{ $row['session_id'] }})" />
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ \Carbon\Carbon::parse($row['date'])->format('D M j, Y') }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ \Carbon\Carbon::createFromFormat('H:i', $row['time'])->format('g:i a') }}</td>
                                    <td class="px-3 py-2">
                                        <a href="{{ route('sessions.show', $row['session_id']) }}" wire:navigate class="hover:underline">{{ implode(', ', $row['names']) }}</a>
                                        <div class="text-xs text-zinc-500">{{ $row['service'] }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['people'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ money($row['subtotal']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600">
                                <td class="px-3 py-2 print:hidden"></td>
                                <td class="px-3 py-2" colspan="3">Cover fees · {{ $coverSessions }} {{ Str::plural('session', $coverSessions) }}{{ $coverExcluded ? ' ('.$coverExcluded.' excluded)' : '' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $coverPeople }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ money($coverSubtotal) }}</td>
                            </tr>
                            @if ($coverGst > 0)
                                <tr><td class="print:hidden"></td><td class="px-3 py-1" colspan="4">GST you charge {{ $coverGstRate === null ? '(mixed rates)' : '('.number_format($coverGstRate, 2).'%)' }}</td><td class="px-3 py-1 text-right tabular-nums">{{ money($coverGst) }}</td></tr>
                            @endif
                            <tr class="font-semibold text-emerald-700 dark:text-emerald-400"><td class="print:hidden"></td><td class="px-3 py-2" colspan="4">Credit to you</td><td class="px-3 py-2 text-right tabular-nums">&minus;{{ money($coverTotal) }}</td></tr>
                            <tr class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600"><td class="print:hidden"></td><td class="px-3 py-2" colspan="4">{{ $netTotal < 0 ? $currentGym->name.' owes you' : 'Total owed to '.$currentGym->name }}</td><td class="px-3 py-2 text-right tabular-nums">{{ money(abs($netTotal)) }}</td></tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-2 text-xs text-zinc-500">
                    This is money the gym owes you for training its own clients. It carries <strong>your</strong> GST, which you collect and remit &mdash;
                    unlike GST on the usage charges above, which the gym charges you and you claim back.
                </div>
            </section>
        @endif
    @endif
</div>
