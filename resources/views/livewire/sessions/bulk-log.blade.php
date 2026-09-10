<div class="max-w-5xl">
    <x-page-header title="Bulk log sessions" subtitle="One set-up, many dates — for catching up on a backlog or importing past sessions. Up to {{ $maxSessions }} at a time.">
        <x-slot:actions>
            <flux:button :href="route('sessions.log')" icon="plus" wire:navigate>Log one session</flux:button>
        </x-slot:actions>
    </x-page-header>

    <form wire:submit="save(true)" class="space-y-8">
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if ($gyms->count() > 1)
                <div class="sm:col-span-2 lg:col-span-4">
                    <flux:select wire:model.live="gym_id" label="Gym" description="Applies to every session in this batch.">
                        <flux:select.option value="">Choose a gym…</flux:select.option>
                        @foreach ($gyms as $gym)
                            <flux:select.option value="{{ $gym->id }}">{{ $gym->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
            <div class="sm:col-span-2">
                <flux:select wire:model.live="service_id" label="Service">
                    @foreach ($services as $service)
                        <flux:select.option value="{{ $service->id }}">{{ $service->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:input wire:model="time" label="Start time" type="time" description="Optional." />
            <flux:input wire:model="duration_minutes" label="Duration (min)" type="number" min="5" max="480" />
            <div class="sm:col-span-2 lg:col-span-4">
                <flux:input wire:model="notes" label="Notes" placeholder="Optional — added to every session in this batch" />
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <flux:heading>Add clients</flux:heading>
                <flux:subheading>The same people attend every session in this batch.</flux:subheading>
                <flux:input wire:model.live.debounce.250ms="clientSearch" icon="magnifying-glass" placeholder="Search clients" class="mt-2" clearable />
                <div class="mt-2 max-h-72 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    @forelse ($candidates as $client)
                        <button type="button" wire:click="addClient({{ $client->id }})" wire:key="cand-{{ $client->id }}"
                            class="flex w-full items-center justify-between border-b border-zinc-100 px-3 py-2 text-left text-sm hover:bg-zinc-50 last:border-b-0 dark:border-zinc-800 dark:hover:bg-zinc-800">
                            <span>{{ $client->full_name }}</span>
                            <span class="text-xs text-zinc-500">{{ $client->plan?->name ?? 'No plan' }}</span>
                        </button>
                    @empty
                        <div class="px-3 py-4 text-sm text-zinc-500">{{ $clientSearch ? 'No matching active clients.' : 'All active clients are already added.' }}</div>
                    @endforelse
                </div>
            </div>

            <div class="lg:col-span-3">
                <div class="flex items-center justify-between">
                    <flux:heading>Attendees</flux:heading>
                    @if ($preview['rows'])
                        <flux:badge>{{ $preview['tier'] }} session · {{ $preview['headcount'] }} {{ Str::plural('person', $preview['headcount']) }}</flux:badge>
                    @endif
                </div>

                @error('attendees')
                    <flux:callout variant="danger" icon="exclamation-triangle" class="mt-2">
                        <flux:callout.text>{{ $message }}</flux:callout.text>
                    </flux:callout>
                @enderror

                @if (empty($preview['rows']))
                    <x-empty-state class="mt-2 p-6" title="No one added yet" description="Pick clients from the list to add them to every session in this batch." />
                @else
                    <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm">
                            <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left font-normal">Client</th>
                                    <th class="px-3 py-2 text-left font-normal">Price override</th>
                                    <th class="px-3 py-2 text-right font-normal">Charge per session</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['rows'] as $clientId => $row)
                                    <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="att-{{ $clientId }}">
                                        <td class="px-3 py-2">
                                            <div class="font-medium">{{ $row['client']->full_name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $row['client']->plan?->name ?? 'No plan' }}</div>
                                        </td>
                                        <td class="px-3 py-2"><flux:input wire:model.live.debounce.400ms="attendees.{{ $clientId }}.override" type="number" step="0.01" min="0" placeholder="Plan rate" class="w-28" /></td>
                                        <td class="px-3 py-2 text-right tabular-nums">
                                            @if ($row['error'])
                                                <span class="text-xs text-red-600 dark:text-red-400">{{ $row['error'] }}</span>
                                            @elseif ($row['total'] == 0)
                                                <span class="text-zinc-500">Included</span>
                                            @else
                                                <div>{{ money($row['total']) }}</div>
                                                <div class="text-xs text-zinc-500">{{ money($row['subtotal']) }} + {{ money($row['gst']) }} GST</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right"><flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeClient({{ $clientId }})" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-5">
            <div class="lg:col-span-3">
                <div class="flex items-center justify-between">
                    <flux:heading>Pick the dates</flux:heading>
                    <flux:button.group>
                        <flux:button size="sm" icon="chevron-left" wire:click="previousMonth" type="button" aria-label="Previous month" />
                        <flux:button size="sm" class="pointer-events-none min-w-32">{{ $monthLabel }}</flux:button>
                        <flux:button size="sm" icon="chevron-right" wire:click="nextMonth" type="button" aria-label="Next month" />
                    </flux:button.group>
                </div>
                <flux:subheading class="mt-1">Click a day to add it, click again to drop it. A time is optional — the date is what matters.</flux:subheading>

                <div class="mt-3 rounded-xl border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="grid grid-cols-7 text-center text-xs font-medium text-zinc-500">
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow)
                            <div class="py-1">{{ $dow }}</div>
                        @endforeach
                    </div>
                    @foreach ($weeks as $week)
                        <div class="grid grid-cols-7 gap-1 py-0.5">
                            @foreach ($week as $day)
                                @php($key = $day->toDateString())
                                @php($picked = in_array($key, $dates, true))
                                <button type="button" wire:click="toggleDate('{{ $key }}')" wire:key="pick-{{ $key }}"
                                    aria-pressed="{{ $picked ? 'true' : 'false' }}"
                                    title="{{ $day->format('l, M j, Y') }}{{ isset($alreadyLogged[$key]) ? ' — already has a session' : '' }}"
                                    class="relative flex h-10 items-center justify-center rounded-lg text-sm transition
                                        {{ $picked
                                            ? 'bg-[var(--color-accent)] font-semibold text-white'
                                            : ($day->month !== $month ? 'text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800') }}
                                        {{ $key === today()->toDateString() ? 'ring-1 ring-[var(--color-accent)]/50' : '' }}">
                                    {{ $day->day }}
                                    @if ($picked && isset($alreadyLogged[$key]))
                                        <span class="absolute right-1 top-0.5 text-[10px] text-amber-200" title="Already has a session">!</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <flux:textarea wire:model="paste" label="Or paste dates" rows="3" placeholder="2026-06-03, 2026-06-05&#10;Jun 10, 2026" description="One per line or separated by commas. Include the year." />
                    @error('paste') <flux:error name="paste">{{ $message }}</flux:error> @enderror
                    <flux:button size="sm" class="mt-2" type="button" icon="plus" wire:click="addPastedDates">Add pasted dates</flux:button>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="flex items-center justify-between">
                    <flux:heading>{{ count($dates) }} of {{ $maxSessions }} {{ Str::plural('session', count($dates)) }}</flux:heading>
                    @if ($dates)
                        <flux:button size="xs" variant="ghost" type="button" wire:click="clearDates">Clear</flux:button>
                    @endif
                </div>

                @error('dates')
                    <flux:callout variant="danger" icon="exclamation-triangle" class="mt-2">
                        <flux:callout.text>{{ $message }}</flux:callout.text>
                    </flux:callout>
                @enderror

                @if (empty($dates))
                    <x-empty-state class="mt-2 p-6" title="No dates picked" description="Click days on the calendar or paste a list." />
                @else
                    @if ($alreadyLogged)
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-2">
                            <flux:callout.text>{{ count($alreadyLogged) }} {{ Str::plural('date', count($alreadyLogged)) }} in this batch already {{ count($alreadyLogged) === 1 ? 'has' : 'have' }} a session for one of these clients. Logging again will charge them twice.</flux:callout.text>
                        </flux:callout>
                    @endif
                    <div class="mt-2 flex max-h-72 flex-wrap gap-1.5 overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                        @foreach ($dates as $date)
                            @php($day = \Carbon\CarbonImmutable::parse($date))
                            <button type="button" wire:click="toggleDate('{{ $date }}')" wire:key="chip-{{ $date }}"
                                class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs hover:border-red-300 hover:text-red-600 dark:hover:border-red-700
                                    {{ isset($alreadyLogged[$date]) ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200' : 'border-zinc-200 dark:border-zinc-700' }}"
                                title="Remove {{ $day->format('l, M j, Y') }}">
                                {{ $day->format('D M j, Y') }}
                                <flux:icon.x-mark class="size-3" />
                            </button>
                        @endforeach
                    </div>

                    <div class="mt-4 rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-500">Charge per session</span>
                            <span class="tabular-nums">{{ money($perSession) }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between">
                            <span class="text-zinc-500">× {{ count($dates) }} {{ Str::plural('session', count($dates)) }}</span>
                            <span class="text-zinc-500 tabular-nums">{{ count($dates) }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-zinc-200 pt-2 font-medium dark:border-zinc-700">
                            <span>Total to Fitness Wallets</span>
                            <span class="tabular-nums">{{ money($grandTotal) }}</span>
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <section>
            <flux:checkbox wire:model="sendReceipts" label="Email attendees a receipt for each session" description="One email per session — usually left off when importing past sessions." />
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary" icon="check">
                {{ count($dates) ? 'Log '.count($dates).' '.Str::plural('session', count($dates)).' & charge' : 'Log sessions & charge' }}
            </flux:button>
            <flux:button type="button" wire:click="save(false)">Save all as scheduled</flux:button>
            <flux:button :href="route('sessions.index')" variant="ghost" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
