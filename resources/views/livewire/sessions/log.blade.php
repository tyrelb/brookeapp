<div class="max-w-4xl">
    @if ($isBooking = $this->isBooking())
        <x-page-header title="Book session" subtitle="Schedule a future session. Clients can get a calendar invite by email; only you can change a booking." />
    @else
        <x-page-header title="Log session" subtitle="Record who trained. The rate tier is picked from how many people attended.">
            <x-slot:actions>
                <flux:button :href="route('sessions.log.bulk')" icon="squares-plus" wire:navigate>Bulk log</flux:button>
            </x-slot:actions>
        </x-page-header>
    @endif

    <form wire:submit="save({{ $isBooking ? 'false' : 'true' }})" class="space-y-8">
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if ($gyms->count() > 1 || $cover)
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
            <flux:input wire:model.live="date" label="Date" type="date" />
            <flux:input wire:model.live="time" label="Start time" type="time" />
            <flux:input wire:model.live.debounce.500ms="duration_minutes" label="Duration (min)" type="number" min="5" max="480" />
            <div class="sm:col-span-2 lg:col-span-3">
                <flux:input wire:model="notes" label="Notes" placeholder="Optional" />
            </div>

            @if ($conflicts->isNotEmpty())
                <div class="sm:col-span-2 lg:col-span-4">
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>{{ $conflicts->count() === 1 ? 'Something else is booked at this time' : 'Other sessions are booked at this time' }}</flux:callout.heading>
                        <flux:callout.text>
                            <ul class="space-y-1">
                                @foreach ($conflicts as $clash)
                                    <li wire:key="clash-{{ $clash->id }}">
                                        <flux:link :href="route('sessions.show', $clash)" wire:navigate>{{ $clash->starts_at->format('g:i a') }} – {{ $clash->endsAt()->format('g:i a') }}</flux:link>
                                        · {{ $clash->service->name }}
                                        · {{ $clash->attendees->pluck('client.full_name')->join(', ') ?: 'no attendees' }}
                                    </li>
                                @endforeach
                            </ul>
                            <span class="mt-1 block">You can {{ $isBooking ? 'book' : 'log' }} this anyway — pick another time if it was a mistake.</span>
                        </flux:callout.text>
                    </flux:callout>
                </div>
            @endif
        </section>

        @if ($coverGyms->isNotEmpty())
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:checkbox wire:model.live="cover"
                    label="I'm covering the gym's own clients"
                    description="For when the gym owner is away. The gym pays you for the session, you owe it nothing, and no client wallet is touched." />
            </section>
        @endif

        @if ($cover)
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading>Who you trained</flux:heading>
                <flux:subheading>The gym's clients, by name. How many you name is what sets the rate.</flux:subheading>

                <div class="mt-3 max-w-md space-y-2">
                    @foreach ($coverNames as $i => $name)
                        <div class="flex items-center gap-2">
                            <flux:input wire:model.live.debounce.400ms="coverNames.{{ $i }}" wire:key="cover-name-{{ $i }}" placeholder="Name" class="flex-1" />
                            @if (count($coverNames) > 1)
                                <flux:button type="button" wire:click="removeCoverName({{ $i }})" variant="subtle" size="sm" icon="x-mark" inset aria-label="Remove" />
                            @endif
                        </div>
                    @endforeach
                </div>

                @if (count($coverNames) < 10)
                    <flux:button type="button" wire:click="addCoverName" variant="ghost" size="sm" icon="plus" class="mt-2">Add another person</flux:button>
                @endif

                @error('coverNames') <flux:error name="coverNames">{{ $message }}</flux:error> @enderror

                @if ($coverPreview)
                    <div class="mt-4">
                        @if ($coverPreview['error'])
                            {{-- Nothing typed yet is not a problem worth shouting about. --}}
                            @if ($coverPreview['fix_in_settings'] ?? false)
                                <flux:callout variant="warning" icon="exclamation-triangle">
                                    <flux:callout.text>
                                        {{ $coverPreview['error'] }}
                                        <flux:link :href="route('settings.gyms')" wire:navigate>Settings → Gyms</flux:link>
                                    </flux:callout.text>
                                </flux:callout>
                            @endif
                        @else
                            <div class="rounded-lg bg-emerald-50 px-4 py-3 text-sm dark:bg-emerald-950/40">
                                <span class="font-medium">{{ $coverPreview['people'] }} {{ Str::plural('person', $coverPreview['people']) }} · {{ money($coverPreview['subtotal']) }}@if ($coverPreview['gst'] > 0) + {{ money($coverPreview['gst']) }} GST = {{ money($coverPreview['total']) }}@endif</span>
                                <span class="text-zinc-600 dark:text-zinc-400">· credited to you on {{ $coverPreview['gym']->name }}'s statement</span>
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        @else
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
                        <flux:badge>{{ $preview['tier'] }} session · {{ $preview['people'] }} {{ Str::plural('person', $preview['people']) }}@if ($preview['tier'] !== $preview['rateTier']) · {{ $preview['rateTier'] }} rate @endif</flux:badge>
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
                                    @php($isFamily = $row['client']->isOnFamilyPlan())
                                    <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="att-{{ $clientId }}">
                                        <td class="px-3 py-2">
                                            <div class="font-medium">{{ $row['client']->full_name }}</div>
                                            <div class="text-xs text-zinc-500">
                                                {{ $row['client']->plan?->name ?? 'No plan' }}{{ $isBooking ? ' · '.($row['client']->email ?: 'no email') : '' }}
                                                @if ($isFamily)
                                                    · {{ $row['people'] }} of {{ count($attendees[$clientId]['members'] ?? []) }} attending
                                                    <button type="button" class="ml-1 underline decoration-dotted underline-offset-2" wire:click="toggleAllMembers({{ $clientId }}, {{ $row['people'] ? 'false' : 'true' }})">{{ $row['people'] ? 'clear' : 'select all' }}</button>
                                                @endif
                                            </div>
                                        </td>
                                        @unless ($isBooking)
                                            <td class="px-3 py-2">@unless ($isFamily)<flux:checkbox wire:model.live="attendees.{{ $clientId }}.attended" />@endunless</td>
                                        @endunless
                                        <td class="px-3 py-2">@unless ($isFamily)<flux:input wire:model.live.debounce.400ms="attendees.{{ $clientId }}.override" type="number" step="0.01" min="0" placeholder="Plan rate" class="w-28" />@endunless</td>
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
                                    @if ($isFamily)
                                        @foreach ($attendees[$clientId]['members'] ?? [] as $memberId => $member)
                                            @php($charge = collect($row['members'])->firstWhere('id', (int) $memberId))
                                            @php($memberName = $row['client']->members->firstWhere('id', (int) $memberId)?->name ?? 'Member')
                                            <tr class="bg-zinc-50/60 text-xs dark:bg-zinc-800/40" wire:key="att-{{ $clientId }}-m-{{ $memberId }}">
                                                <td class="py-1.5 pl-8 pr-3">
                                                    <flux:checkbox wire:model.live="attendees.{{ $clientId }}.members.{{ $memberId }}.attended" :label="$memberName" />
                                                </td>
                                                @unless ($isBooking)<td></td>@endunless
                                                <td class="px-3 py-1.5"><flux:input wire:model.live.debounce.400ms="attendees.{{ $clientId }}.members.{{ $memberId }}.override" type="number" step="0.01" min="0" placeholder="Plan rate" class="w-28" size="sm" /></td>
                                                <td class="px-3 py-1.5 text-right tabular-nums text-zinc-500">{{ $charge ? money($charge['subtotal']) : '—' }}</td>
                                                <td></td>
                                            </tr>
                                        @endforeach
                                    @endif
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
        @endif

        @if ($isBooking && ! $cover)
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
                    @if ($repeatConflicts > 0)
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4">
                            <flux:callout.text>{{ $repeatConflicts }} of these {{ Str::plural('date', $repeatConflicts) }} already {{ $repeatConflicts === 1 ? 'has' : 'have' }} a session at this time. They will be booked on top of it.</flux:callout.text>
                        </flux:callout>
                    @endif
                    @error('until') <flux:error name="until">{{ $message }}</flux:error> @enderror
                    @error('weekdays') <flux:error name="weekdays">{{ $message }}</flux:error> @enderror
                @endif
            </section>
        @endif

        <section @if ($cover) hidden @endif>
            @if ($isBooking)
                <flux:checkbox wire:model="sendInvites" label="Email attendees a calendar invite" description="Includes an .ics they can accept, plus your booking instructions for changes. Clients without an email address are skipped." />
            @else
                <flux:checkbox wire:model="sendReceipts" label="Email attendees a receipt" description="Shows what was deducted and their remaining Fitness Wallet balance, with your booking instructions for next time." />
            @endif
        </section>

        <div class="flex flex-wrap items-center gap-3">
            @if ($isBooking)
                <flux:button type="submit" variant="primary" icon="calendar">{{ ($repeat && $repeatPreview && ! $repeatPreview['error'] ? 'Book '.$repeatPreview['count'].' '.Str::plural('session', $repeatPreview['count']) : 'Book session').($conflicts->isNotEmpty() || $repeatConflicts > 0 ? ' anyway' : '') }}</flux:button>
                <flux:button :href="route('sessions.calendar', ['date' => $date])" variant="ghost" wire:navigate>Cancel</flux:button>
            @else
                <flux:button type="submit" variant="primary" icon="check">{{ $cover ? 'Log cover session' : 'Complete & charge' }}</flux:button>
                <flux:button type="button" wire:click="save(false)">Save as scheduled</flux:button>
                <flux:button :href="route('sessions.index')" variant="ghost" wire:navigate>Cancel</flux:button>
            @endif
        </div>
    </form>
</div>
