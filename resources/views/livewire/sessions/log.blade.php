<div class="max-w-4xl">
    @if ($isBooking = $this->isBooking())
        <x-page-header title="Book session" subtitle="Schedule a future session. Clients can get a calendar invite by email; only you can change a booking." />
    @else
        <x-page-header title="Log session" subtitle="Record who trained. The rate tier is picked from how many people attended." />
    @endif

    <form wire:submit="save({{ $isBooking ? 'false' : 'true' }})" class="space-y-8">
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if ($gyms->count() > 1)
                <div class="sm:col-span-2 lg:col-span-4">
                    <flux:select wire:model.live="gym_id" label="Gym" description="Where this session happens; used for the gym usage report. Pre-filled from the first client's default gym.">
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
            <flux:input wire:model="date" label="Date" type="date" />
            <flux:input wire:model="time" label="Start time" type="time" />
            <flux:input wire:model="duration_minutes" label="Duration (min)" type="number" min="5" max="480" />
            <div class="sm:col-span-2 lg:col-span-3">
                <flux:input wire:model="notes" label="Notes" placeholder="Optional" />
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <flux:heading>Add clients</flux:heading>
                <flux:input wire:model.live.debounce.250ms="clientSearch" icon="magnifying-glass" placeholder="Search clients" class="mt-2" clearable />
                <div class="mt-2 max-h-80 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    @forelse ($candidates as $client)
                        <button type="button" wire:click="addClient({{ $client->id }})" wire:key="cand-{{ $client->id }}"
                            class="flex w-full items-center justify-between border-b border-zinc-100 px-3 py-2 text-left text-sm hover:bg-zinc-50 last:border-b-0 dark:border-zinc-800 dark:hover:bg-zinc-800">
                            <span>{{ $client->full_name }}@if ($isBooking && ! $client->email) <span class="text-xs text-amber-600">(no email)</span>@endif</span>
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
                    <x-empty-state class="mt-2 p-6" title="No one added yet" description="Pick clients from the list to add them to this session." />
                @else
                    <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm">
                            <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 text-left font-normal">Client</th>
                                    @unless ($isBooking)
                                        <th class="px-3 py-2 text-left font-normal">Attended</th>
                                    @endunless
                                    <th class="px-3 py-2 text-left font-normal">Price override</th>
                                    <th class="px-3 py-2 text-right font-normal">{{ $isBooking ? 'Expected charge' : 'Charge' }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['rows'] as $clientId => $row)
                                    <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="att-{{ $clientId }}">
                                        <td class="px-3 py-2">
                                            <div class="font-medium">{{ $row['client']->full_name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $row['client']->plan?->name ?? 'No plan' }}{{ $isBooking ? ' · '.($row['client']->email ?: 'no email') : '' }}</div>
                                        </td>
                                        @unless ($isBooking)
                                            <td class="px-3 py-2"><flux:checkbox wire:model.live="attendees.{{ $clientId }}.attended" /></td>
                                        @endunless
                                        <td class="px-3 py-2"><flux:input wire:model.live.debounce.400ms="attendees.{{ $clientId }}.override" type="number" step="0.01" min="0" placeholder="Plan rate" class="w-28" /></td>
                                        <td class="px-3 py-2 text-right tabular-nums">
                                            @if (! $row['attended'])
                                                <span class="text-zinc-400">No-show</span>
                                            @elseif ($row['error'])
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
                            <tfoot>
                                <tr class="border-t border-zinc-200 font-medium dark:border-zinc-700">
                                    <td class="px-3 py-2" colspan="{{ $isBooking ? 2 : 3 }}">{{ $isBooking ? 'Expected total when completed' : 'Total to Fitness Wallets' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ money($preview['total']) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </section>

        @if ($isBooking)
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:checkbox wire:model.live="repeat" label="Repeat this booking" description="Books one session per date, all with the same clients. Every repeat needs an end date." />
                @if ($repeat)
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <flux:select wire:model.live="intervalWeeks" label="Repeats">
                            @foreach ($intervals as $weeks => $label)
                                <flux:select.option value="{{ $weeks }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="sm:col-span-2">
                            <flux:checkbox.group wire:model.live="weekdays" label="On" variant="cards" class="grid grid-cols-7 gap-1">
                                @foreach ($weekdayNames as $n => $name)
                                    <flux:checkbox value="{{ $n }}" label="{{ $name }}" />
                                @endforeach
                            </flux:checkbox.group>
                        </div>
                        <flux:input wire:model.live="until" label="Until (last possible date)" type="date" />
                        <div class="sm:col-span-2 self-end text-sm">
                            @if ($repeatPreview['error'])
                                <span class="text-red-600 dark:text-red-400">{{ $repeatPreview['error'] }}</span>
                            @else
                                <span class="font-medium">Creates {{ $repeatPreview['count'] }} {{ Str::plural('session', $repeatPreview['count']) }}</span>
                                <span class="text-zinc-500">· {{ $repeatPreview['description'] }} · last on {{ $repeatPreview['last'] }}</span>
                            @endif
                        </div>
                    </div>
                    @error('until') <flux:error name="until">{{ $message }}</flux:error> @enderror
                    @error('weekdays') <flux:error name="weekdays">{{ $message }}</flux:error> @enderror
                @endif
            </section>
        @endif

        <section>
            @if ($isBooking)
                <flux:checkbox wire:model="sendInvites" label="Email attendees a calendar invite" description="Includes an .ics they can accept, plus your booking instructions for changes. Clients without an email address are skipped." />
            @else
                <flux:checkbox wire:model="sendReceipts" label="Email attendees a receipt" description="Shows what was deducted and their remaining Fitness Wallet balance, with your booking instructions for next time." />
            @endif
        </section>

        <div class="flex flex-wrap items-center gap-3">
            @if ($isBooking)
                <flux:button type="submit" variant="primary" icon="calendar">{{ $repeat && $repeatPreview && ! $repeatPreview['error'] ? 'Book '.$repeatPreview['count'].' '.Str::plural('session', $repeatPreview['count']) : 'Book session' }}</flux:button>
                <flux:button :href="route('sessions.calendar', ['date' => $date])" variant="ghost" wire:navigate>Cancel</flux:button>
            @else
                <flux:button type="submit" variant="primary" icon="check">Complete &amp; charge</flux:button>
                <flux:button type="button" wire:click="save(false)">Save as scheduled</flux:button>
                <flux:button :href="route('sessions.index')" variant="ghost" wire:navigate>Cancel</flux:button>
            @endif
        </div>
    </form>
</div>
