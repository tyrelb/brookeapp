<div class="max-w-4xl space-y-6">
    <x-page-header :title="$session->service->name" :subtitle="$session->starts_at->format('l, F j, Y \a\t g:i a').' – '.$session->endsAt()->format('g:i a').' · '.$session->duration_minutes.' min'.($session->gym ? ' · '.$session->gym->name : '')">
        <x-slot:actions>
            <flux:badge :color="$session->status->color()">{{ $session->status->label() }}</flux:badge>
            @if ($session->isInSeries())
                <a href="{{ route('sessions.index', ['series' => $session->session_series_id]) }}" wire:navigate><flux:badge icon="arrow-path" color="purple">{{ $session->series->describe() }}{{ $following ? " · {$following} more" : '' }}</flux:badge></a>
            @endif
            @if ($session->isScheduled())
                <flux:button variant="primary" icon="check" wire:click="complete">{{ $session->isCover() ? 'Complete session' : 'Complete & charge' }}</flux:button>
                <flux:modal.trigger name="reschedule"><flux:button icon="clock">Reschedule</flux:button></flux:modal.trigger>
                @unless ($session->isCover())
                    <flux:button icon="envelope" wire:click="sendInvites" wire:confirm="Email a calendar invite to every attendee with an email address?">{{ $session->invitesWereSent() ? 'Resend invites' : 'Send invites' }}</flux:button>
                @endunless
                <flux:button wire:click="cancel" wire:confirm="Cancel this session? No one will be charged.{{ $session->invitesWereSent() ? ' Attendees who received an invite will be emailed a cancellation.' : '' }}">{{ $session->isInSeries() ? 'Cancel this session' : 'Cancel session' }}</flux:button>
                @if ($session->isInSeries() && $following > 0)
                    <flux:button variant="danger" wire:click="cancelFollowing" wire:confirm="Cancel this session and the {{ $following }} following {{ Str::plural('session', $following) }}? Earlier sessions are untouched. Invited clients get one cancellation email.">Cancel this &amp; following</flux:button>
                @endif
                <flux:button variant="ghost" wire:click="delete" wire:confirm="Delete this session entirely?{{ $session->invitesWereSent() ? ' Attendees who received an invite will be emailed a cancellation.' : '' }}">Delete</flux:button>
            @elseif ($session->isCompleted())
                @unless ($session->isCover())
                    <flux:button icon="envelope" wire:click="sendReceiptsNow" wire:confirm="Email a receipt to everyone who attended and has an email address?">{{ $session->attendees->whereNotNull('receipt_sent_at')->isNotEmpty() ? 'Resend receipts' : 'Email receipts' }}</flux:button>
                @endunless
                <flux:button icon="arrow-uturn-left" wire:click="reopen" wire:confirm="Reopen this session? All charges will be voided so you can correct attendance and complete it again.">Reopen</flux:button>
            @else
                <flux:button wire:click="uncancel">Restore to scheduled</flux:button>
                <flux:button variant="ghost" wire:click="delete" wire:confirm="Delete this session entirely?">Delete</flux:button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('attendees')
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @if ($session->isScheduled())
        <flux:callout icon="information-circle">
            <flux:callout.text>
                @if ($session->isCover())
                    This session hasn't been credited yet. Check the names below, then <strong>Complete session</strong>. The fee follows how many people you trained, and is fixed at that point.
                @else
                    This session hasn't been charged yet. Tick who attended, adjust any price overrides, then <strong>Complete &amp; charge</strong>. The rate tier follows the number of people who attended.
                @endif
                @if ($session->invitesWereSent())
                    Calendar invites were sent {{ $session->invites_sent_at->diffForHumans() }}; rescheduling or cancelling will email an update automatically.
                @endif
            </flux:callout.text>
        </flux:callout>

        @if ($session->isCover())
            @include('livewire.sessions.partials.cover-panel')
        @else
        <section class="grid gap-6 lg:grid-cols-5">
            <div class="lg:col-span-3">
                <div class="flex items-center justify-between">
                    <flux:heading>Attendees</flux:heading>
                    <flux:badge>{{ $preview['tier'] }} · {{ $preview['people'] }} {{ Str::plural('person', $preview['people']) }}@if ($preview['tier'] !== $preview['rateTier']) · {{ $preview['rateTier'] }} rate @endif</flux:badge>
                </div>
                <div class="mt-2 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                <th class="px-3 py-2 text-left font-normal">Client</th>
                                <th class="px-3 py-2 text-left font-normal">Attended</th>
                                <th class="px-3 py-2 text-left font-normal">Override</th>
                                <th class="px-3 py-2 text-right font-normal">Charge</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($preview['rows'] as $clientId => $row)
                                @php($attendee = $session->attendees->firstWhere('client_id', $clientId))
                                @php($isFamily = $row['client']->isOnFamilyPlan())
                                <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="att-{{ $clientId }}">
                                    <td class="px-3 py-2">
                                        <flux:link :href="route('clients.show', $row['client'])" wire:navigate>{{ $row['client']->full_name }}</flux:link>
                                        <div class="text-xs text-zinc-500">
                                            {{ $row['client']->plan?->name ?? 'No plan' }}@if ($attendee?->invite_sent_at) · invited {{ $attendee->invite_sent_at->format('M j') }}@elseif (! $row['client']->email) · no email @endif
                                            @if ($isFamily)
                                                · {{ $row['people'] }} of {{ count($attendees[$clientId]['members'] ?? []) }} attending
                                                <button type="button" class="ml-1 underline decoration-dotted underline-offset-2" wire:click="toggleAllMembers({{ $clientId }}, {{ $row['people'] ? 'false' : 'true' }})">{{ $row['people'] ? 'clear' : 'select all' }}</button>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">@unless ($isFamily)<flux:checkbox wire:model.live="attendees.{{ $clientId }}.attended" />@endunless</td>
                                    <td class="px-3 py-2">@unless ($isFamily)<flux:input wire:model.live.debounce.400ms="attendees.{{ $clientId }}.override" type="number" step="0.01" min="0" placeholder="Plan rate" class="w-28" />@endunless</td>
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        @if (! $row['attended']) <span class="text-zinc-400">No-show</span>
                                        @elseif ($row['error']) <span class="text-xs text-red-600 dark:text-red-400">{{ $row['error'] }}</span>
                                        @elseif ($row['total'] == 0) <span class="text-zinc-500">Included</span>
                                        @else {{ money($row['total']) }} <div class="text-xs text-zinc-500">{{ money($row['subtotal']) }} + {{ money($row['gst']) }} GST</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right"><flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeClient({{ $clientId }})" /></td>
                                </tr>
                                @if ($isFamily)
                                    @foreach ($attendees[$clientId]['members'] ?? [] as $memberId => $member)
                                        @php($charge = collect($row['members'])->firstWhere('id', (int) $memberId))
                                        @php($memberName = $row['client']->members->firstWhere('id', (int) $memberId)?->name ?? 'Member')
                                        <tr class="bg-zinc-50/60 text-xs dark:bg-zinc-800/40" wire:key="att-{{ $clientId }}-m-{{ $memberId }}">
                                            <td class="py-1.5 pl-8 pr-3"><flux:checkbox wire:model.live="attendees.{{ $clientId }}.members.{{ $memberId }}.attended" :label="$memberName" /></td>
                                            <td></td>
                                            <td class="px-3 py-1.5"><flux:input wire:model.live.debounce.400ms="attendees.{{ $clientId }}.members.{{ $memberId }}.override" type="number" step="0.01" min="0" placeholder="Plan rate" class="w-28" size="sm" /></td>
                                            <td class="px-3 py-1.5 text-right tabular-nums text-zinc-500">{{ $charge ? money($charge['subtotal']) : '—' }}</td>
                                            <td></td>
                                        </tr>
                                    @endforeach
                                @endif
                            @empty
                                <tr><td colspan="5" class="px-3 py-4 text-sm text-zinc-500">No clients added yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex items-center gap-3">
                    <flux:input wire:model="notes" placeholder="Notes" class="flex-1" />
                    <flux:button wire:click="saveAttendance">Save</flux:button>
                </div>
                <div class="mt-3">
                    @unless ($session->isCover())
                        <flux:checkbox wire:model="sendReceipts" label="Email attendees a receipt when completed" description="Shows the charge and their remaining balance." />
                    @endunless
                </div>
                @if ($session->isInSeries() && $following > 0)
                    <div class="mt-3">
                        <flux:button size="sm" icon="arrow-path" wire:click="applyAttendeesToFollowing" wire:confirm="Copy this session's attendee list to the {{ $following }} following {{ Str::plural('session', $following) }}? Earlier sessions are untouched.">Apply attendees to following {{ Str::plural('session', $following) }}</flux:button>
                    </div>
                @endif
            </div>
            <div class="lg:col-span-2">
                <flux:heading>Add clients</flux:heading>
                <flux:input wire:model.live.debounce.250ms="clientSearch" icon="magnifying-glass" placeholder="Search clients" class="mt-2" clearable />
                <div class="mt-2 max-h-72 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    @forelse ($candidates as $client)
                        <button type="button" wire:click="addClient({{ $client->id }})" wire:key="cand-{{ $client->id }}"
                            class="flex w-full items-center justify-between border-b border-zinc-100 px-3 py-2 text-left text-sm hover:bg-zinc-50 last:border-b-0 dark:border-zinc-800 dark:hover:bg-zinc-800">
                            <span>{{ $client->full_name }}</span>
                            <span class="text-xs text-zinc-500">{{ $client->plan?->name ?? 'No plan' }}</span>
                        </button>
                    @empty
                        <div class="px-3 py-4 text-sm text-zinc-500">No more active clients to add.</div>
                    @endforelse
                </div>
                @if ($session->invitesWereSent())
                    <flux:text class="mt-2 text-xs">Clients added after invites went out won't have one yet. Use <strong>Resend invites</strong> to include them.</flux:text>
                @endif
            </div>
        </section>

        <flux:modal name="reschedule" class="md:w-[28rem]">
            <form wire:submit="reschedule" class="space-y-5">
                <div>
                    <flux:heading size="lg">Reschedule session</flux:heading>
                    <flux:subheading>{{ $session->invitesWereSent() ? 'Attendees who received an invite will be emailed the updated time automatically.' : 'No invites have been sent for this session yet.' }}</flux:subheading>
                </div>
                <flux:select wire:model="newServiceId" label="Service">
                    @foreach ($services as $service)
                        <flux:select.option value="{{ $service->id }}">{{ $service->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($gyms->isNotEmpty())
                    <flux:select wire:model="newGymId" label="Gym">
                        <flux:select.option value="">Choose a gym…</flux:select.option>
                        @foreach ($gyms as $gym)
                            <flux:select.option value="{{ $gym->id }}">{{ $gym->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="newDate" label="Date" type="date" />
                    <flux:input wire:model="newTime" label="Start time" type="time" />
                </div>
                <flux:input wire:model="newDuration" label="Duration (min)" type="number" min="5" max="480" />
                @if ($session->isInSeries() && $following > 0)
                    <flux:radio.group wire:model="rescheduleScope" label="Apply to">
                        <flux:radio value="one" label="Only this session" />
                        <flux:radio value="following" label="This and the {{ $following }} following {{ Str::plural('session', $following) }}" description="Following sessions move by the same number of days and take the new time, length, service and gym. Earlier sessions are untouched." />
                    </flux:radio.group>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Save new time</flux:button>
                </div>
            </form>
        </flux:modal>
        @endif
    @else
        @if ($session->isCover())
            @include('livewire.sessions.partials.cover-panel')
        @else
        <section>
            <flux:heading class="mb-2">Attendees</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Client</flux:table.column>
                    <flux:table.column>Plan</flux:table.column>
                    <flux:table.column>Attended</flux:table.column>
                    <flux:table.column align="end">Before GST</flux:table.column>
                    <flux:table.column align="end">GST</flux:table.column>
                    <flux:table.column align="end">Charged</flux:table.column>
                    <flux:table.column>Receipt</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($session->attendees as $attendee)
                        <flux:table.row :key="$attendee->id">
                            <flux:table.cell variant="strong"><flux:link :href="route('clients.show', $attendee->client)" wire:navigate>{{ $attendee->client->full_name }}</flux:link></flux:table.cell>
                            <flux:table.cell>{{ $attendee->client->plan?->name ?? 'No plan' }}</flux:table.cell>
                            <flux:table.cell>{{ $attendee->attended ? 'Yes' : 'No-show' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $session->isCompleted() && $attendee->attended ? money($attendee->subtotal) : '' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $session->isCompleted() && $attendee->attended ? money($attendee->gst_amount) : '' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($session->isCompleted() && $attendee->attended)
                                    {{ (float) $attendee->total > 0 ? money($attendee->total) : 'Included' }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-xs">{{ $attendee->receipt_sent_at ? 'Sent '.$attendee->receipt_sent_at->format('M j') : ($attendee->client->email ? '' : 'No email') }}</flux:table.cell>
                        </flux:table.row>
                        @foreach ($attendee->members->where('attended', true) as $member)
                            <flux:table.row :key="'m-'.$member->id">
                                <flux:table.cell class="pl-8 text-xs text-zinc-500">{{ $member->member_name }}</flux:table.cell>
                                <flux:table.cell></flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">Yes</flux:table.cell>
                                <flux:table.cell align="end" class="text-xs text-zinc-500">{{ $session->isCompleted() ? money($member->subtotal) : '' }}</flux:table.cell>
                                <flux:table.cell></flux:table.cell>
                                <flux:table.cell></flux:table.cell>
                                <flux:table.cell></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <div class="mt-3 text-sm text-zinc-500">
                {{ \App\Models\Plan::headcountLabel($session->headcount()) }} session · {{ $session->headcount() }} attended
                @if ($session->isCompleted()) · total charged {{ money($session->attendees->sum('total')) }} · completed {{ $session->completed_at?->format('M j, Y g:i a') }} @endif
            </div>
            @if ($session->notes)
                <flux:text class="mt-3 whitespace-pre-line text-sm">{{ $session->notes }}</flux:text>
            @endif
        </section>
        @endif
    @endif
    @if ($gyms->isNotEmpty())
        <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 print:hidden">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="w-full sm:w-72">
                    <flux:select wire:model.live="newGymId" wire:change="setGym($event.target.value)" label="Gym" description="Used for the gym usage report.">
                        @if (! $session->gym_id)
                            <flux:select.option value="">Choose a gym…</flux:select.option>
                        @endif
                        @foreach ($gyms as $gym)
                            <flux:select.option value="{{ $gym->id }}">{{ $gym->name }}{{ $gym->active ? '' : ' (inactive)' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @if ($session->isCover())
                    <flux:checkbox :checked="$session->gym_billable" wire:click="toggleGymBillable" label="Credited on the gym statement" description="Untick to leave this session off the gym's statement. It stops counting as revenue too." />
                @else
                    <flux:checkbox :checked="$session->gym_billable" wire:click="toggleGymBillable" label="Counts toward gym usage" description="Untick if the gym shouldn't charge for this session." />
                @endif
            </div>
        </section>
    @endif
</div>
