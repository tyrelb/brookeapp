<div>
    {{-- A bottom sheet on a phone; on a wider screen it narrows to a centred panel. --}}
    <flux:modal name="session-sheet" variant="flyout" position="bottom"
        class="max-h-[90dvh] rounded-t-2xl p-5! md:mx-auto md:mb-6 md:min-w-0 md:max-w-lg md:rounded-2xl md:border">
        <div wire:loading.flex wire:target="open" class="flex-col gap-3" aria-hidden="true">
            <div class="h-6 w-48 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
            <div class="h-4 w-64 animate-pulse rounded bg-zinc-100 dark:bg-zinc-700/60"></div>
            <div class="h-4 w-40 animate-pulse rounded bg-zinc-100 dark:bg-zinc-700/60"></div>
            <div class="mt-3 h-10 w-full animate-pulse rounded-lg bg-zinc-200 dark:bg-zinc-700"></div>
        </div>

        @if ($session)
            @php($when = $session->starts_at->format('l, M j').' · '.$session->starts_at->format('g:i a').' – '.$session->endsAt()->format('g:i a'))
            <div wire:loading.remove wire:target="open">
                @if ($mode === 'summary')
                    <div class="space-y-5">
                        <div class="pr-8">
                            <flux:heading size="lg" class="{{ $session->isCancelled() ? 'text-zinc-500 line-through' : '' }}">{{ $session->displayName() }}</flux:heading>
                            <flux:text class="mt-1">{{ $when }} · {{ $session->duration_minutes }} min</flux:text>
                        </div>

                        <div class="space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <div class="flex items-center gap-2"><flux:icon.tag variant="mini" class="shrink-0 text-zinc-400" /> {{ $session->service->name }}</div>
                            @if ($session->gym)
                                <div class="flex items-center gap-2"><flux:icon.map-pin variant="mini" class="shrink-0 text-zinc-400" /> {{ $session->gym->name }}</div>
                            @endif
                            @if ($session->isInSeries())
                                <div class="flex items-center gap-2"><flux:icon.arrow-path variant="mini" class="shrink-0 text-zinc-400" /> {{ $session->series->describe() }}</div>
                            @endif
                            @if ($session->notes)
                                <div class="flex items-start gap-2"><flux:icon.document-text variant="mini" class="mt-0.5 shrink-0 text-zinc-400" /> <span class="whitespace-pre-line">{{ $session->notes }}</span></div>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <flux:badge size="sm" :color="$session->status->color()">{{ $session->status->label() }}</flux:badge>
                            @if ($session->isScheduled() && $session->endsAt()->isPast())
                                <span class="text-amber-700 dark:text-amber-400">Not logged yet</span>
                            @elseif ($session->isCompleted())
                                <span class="text-zinc-500">{{ $session->isCover() ? money($session->coverTotal()).' credited' : money($session->attendees->sum('total')).' charged' }}</span>
                            @endif
                        </div>

                        @if ($session->isScheduled())
                            <div class="grid gap-2">
                                <flux:button variant="primary" icon="check" wire:click="switchTo('log')" class="w-full">Log session</flux:button>
                                <div class="grid grid-cols-2 gap-2">
                                    <flux:button icon="clock" wire:click="switchTo('edit')">Edit</flux:button>
                                    <flux:button icon="x-mark" wire:click="switchTo('cancel')">Cancel</flux:button>
                                </div>
                            </div>
                        @endif

                        <flux:link :href="route('sessions.show', $session)" wire:navigate class="inline-block text-sm">Open full session →</flux:link>
                    </div>
                @elseif ($mode === 'log')
                    <form wire:submit="log" class="space-y-4">
                        <div class="flex items-center gap-1 pr-8">
                            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="switchTo('summary')" aria-label="Back" />
                            <flux:heading size="lg">Log session</flux:heading>
                        </div>
                        <flux:text>{{ $when }}</flux:text>

                        @error('attendees')
                            <flux:callout variant="danger" icon="exclamation-triangle">
                                <flux:callout.text>{{ $message }}</flux:callout.text>
                            </flux:callout>
                        @enderror

                        @if ($session->isCover())
                            <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                                <div class="font-medium">{{ $session->displayName() }}</div>
                                <div class="mt-1 text-zinc-500">Covering for {{ $session->gym?->name ?? 'the gym' }}. The fee follows how many people you trained. Change the names on the full session page.</div>
                            </div>
                        @else
                            <div>
                                <flux:heading size="sm" class="mb-2">Who came?</flux:heading>
                                <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                                    @foreach ($attendees as $clientId => $state)
                                        @php($row = $preview['rows'][$clientId] ?? null)
                                        @continue(! $row)
                                        @php($client = $row['client'])
                                        @php($isFamily = $client->isOnFamilyPlan())
                                        @php($isLate = \App\Enums\Attendance::fromInput($state['attendance'] ?? null) === \App\Enums\Attendance::LateCancel)
                                        <div class="space-y-2.5 p-3" wire:key="sheet-att-{{ $clientId }}">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0 font-medium">
                                                    {{ $client->full_name }}
                                                    @if ($isFamily) <span class="text-xs font-normal text-zinc-500">· family</span> @endif
                                                </div>
                                                <div class="shrink-0 text-right text-sm tabular-nums">
                                                    @if (! $row['attended']) <span class="text-zinc-400">{{ $isFamily ? 'No one ticked' : 'No-show' }}</span>
                                                    @elseif ($row['error']) <span class="text-xs text-red-600 dark:text-red-400">{{ $row['error'] }}</span>
                                                    @elseif ($row['total'] == 0) <span class="text-zinc-500">Included</span>
                                                    @else {{ money($row['total']) }}
                                                    @endif
                                                </div>
                                            </div>
                                            <flux:select wire:model.live="attendees.{{ $clientId }}.attendance" size="sm" aria-label="How {{ $client->first_name }} took part">
                                                @foreach (\App\Enums\Attendance::cases() as $option)
                                                    @if (! $isFamily || $option !== \App\Enums\Attendance::NoShow)
                                                        <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                                                    @endif
                                                @endforeach
                                            </flux:select>
                                            @if ($isFamily)
                                                <div class="flex flex-wrap gap-x-5 gap-y-2">
                                                    @foreach ($state['members'] ?? [] as $memberId => $member)
                                                        <flux:checkbox wire:key="sheet-att-{{ $clientId }}-m-{{ $memberId }}" wire:model.live="attendees.{{ $clientId }}.members.{{ $memberId }}.attended" :label="$client->members->firstWhere('id', (int) $memberId)?->name ?? 'Member'" />
                                                    @endforeach
                                                </div>
                                            @endif
                                            @if ($isLate || filled($state['client_note'] ?? ''))
                                                <div>
                                                    <flux:input wire:model="attendees.{{ $clientId }}.client_note" size="sm" maxlength="150"
                                                        :placeholder="$isLate ? 'Reason (required) — '.($isFamily ? 'the family' : $client->first_name).' sees this' : 'Note for '.$client->first_name"
                                                        :aria-label="$isLate ? 'Reason for the late cancel' : 'Note for the client'" />
                                                    <flux:error name="attendees.{{ $clientId }}.client_note" />
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                @if ($preview)
                                    <div class="mt-2 flex items-center justify-between text-sm text-zinc-500">
                                        <span>{{ $preview['tier'] }} · {{ $preview['people'] }} {{ Str::plural('person', $preview['people']) }}</span>
                                        <span class="font-medium text-zinc-900 tabular-nums dark:text-zinc-100">Total {{ money($preview['total']) }}</span>
                                    </div>
                                @endif
                            </div>
                            <flux:checkbox wire:model="sendReceipts" label="Email attendees a receipt" />
                        @endif

                        <flux:button type="submit" variant="primary" icon="check" class="w-full">{{ $session->isCover() ? 'Log session' : 'Log & charge' }}</flux:button>
                    </form>
                @elseif ($mode === 'edit')
                    <form wire:submit="reschedule" class="space-y-4">
                        <div class="flex items-center gap-1 pr-8">
                            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="switchTo('summary')" aria-label="Back" />
                            <flux:heading size="lg">Edit time</flux:heading>
                        </div>
                        @if ($session->invitesWereSent())
                            <flux:text>Invited clients will be emailed the new time.</flux:text>
                        @endif

                        <div class="grid grid-cols-2 gap-3">
                            <flux:input wire:model.live="newDate" label="Date" type="date" />
                            <flux:input wire:model.live="newTime" label="Start time" type="time" />
                        </div>
                        @php($lengths = collect([30, 45, 60, 75, 90, 120])->push((int) $newDuration)->filter()->unique()->sort()->values())
                        <flux:select wire:model.live="newDuration" label="Length">
                            @foreach ($lengths as $minutes)
                                <flux:select.option value="{{ $minutes }}">{{ $minutes }} min</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="newDuration" />

                        @if ($session->isInSeries() && $following > 0)
                            <flux:radio.group wire:model="rescheduleScope" label="Apply to">
                                <flux:radio value="one" label="Only this session" />
                                <flux:radio value="following" label="This and the {{ $following }} following {{ Str::plural('session', $following) }}" description="They move by the same number of days and take the new time and length." />
                            </flux:radio.group>
                        @endif

                        @if ($conflicts->isNotEmpty())
                            <flux:callout variant="warning" icon="exclamation-triangle">
                                <flux:callout.heading>{{ $conflicts->count() === 1 ? 'Something else is booked at this time' : 'Other sessions are booked at this time' }}</flux:callout.heading>
                                <flux:callout.text>
                                    <ul class="space-y-1">
                                        @foreach ($conflicts as $clash)
                                            <li wire:key="sheet-clash-{{ $clash->id }}">{{ $clash->starts_at->format('g:i a') }} – {{ $clash->endsAt()->format('g:i a') }} · {{ $clash->displayName() }}</li>
                                        @endforeach
                                    </ul>
                                    <span class="mt-1 block">You can still save it.</span>
                                </flux:callout.text>
                            </flux:callout>
                        @endif

                        <flux:button type="submit" variant="primary" class="w-full">{{ $conflicts->isNotEmpty() ? 'Save anyway' : 'Save new time' }}</flux:button>
                        <flux:link :href="route('sessions.show', $session)" wire:navigate class="inline-block text-sm">Change the service, gym or clients on the full session page →</flux:link>
                    </form>
                @elseif ($mode === 'cancel')
                    <div class="space-y-4">
                        <div class="flex items-center gap-1 pr-8">
                            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="switchTo('summary')" aria-label="Back" />
                            <flux:heading size="lg">Cancel session?</flux:heading>
                        </div>
                        <flux:text>
                            {{ $session->displayName() }}, {{ $when }}. No one will be charged.
                            @if ($session->invitesWereSent()) Invited clients will be emailed a cancellation. @endif
                        </flux:text>
                        <div class="grid gap-2">
                            <flux:button variant="danger" wire:click="cancel" class="w-full">Cancel this session</flux:button>
                            @if ($session->isInSeries() && $following > 0)
                                <flux:button variant="danger" wire:click="cancelFollowing" class="w-full">Cancel this &amp; {{ $following }} following</flux:button>
                            @endif
                            <flux:button variant="ghost" wire:click="switchTo('summary')" class="w-full">Keep it</flux:button>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
