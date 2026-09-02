<div class="max-w-4xl space-y-6">
    <x-page-header :title="$session->service->name" :subtitle="$session->starts_at->format('l, F j, Y \a\t g:i a').' · '.$session->duration_minutes.' min'">
        <x-slot:actions>
            <flux:badge :color="$session->status->color()">{{ $session->status->label() }}</flux:badge>
            @if ($session->isScheduled())
                <flux:button variant="primary" icon="check" wire:click="complete">Complete &amp; charge</flux:button>
                <flux:button wire:click="cancel" wire:confirm="Cancel this session? No one will be charged.">Cancel session</flux:button>
                <flux:button variant="ghost" wire:click="delete" wire:confirm="Delete this session entirely?">Delete</flux:button>
            @elseif ($session->isCompleted())
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
            <flux:callout.text>This session hasn't been charged yet. Tick who attended, adjust any price overrides, then <strong>Complete &amp; charge</strong>. The rate tier follows the number of people who attended.</flux:callout.text>
        </flux:callout>

        <section class="grid gap-6 lg:grid-cols-5">
            <div class="lg:col-span-3">
                <div class="flex items-center justify-between">
                    <flux:heading>Attendees</flux:heading>
                    <flux:badge>{{ $preview['tier'] }} · {{ $preview['headcount'] }} {{ Str::plural('person', $preview['headcount']) }}</flux:badge>
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
                                <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="att-{{ $clientId }}">
                                    <td class="px-3 py-2">
                                        <flux:link :href="route('clients.show', $row['client'])" wire:navigate>{{ $row['client']->full_name }}</flux:link>
                                        <div class="text-xs text-zinc-500">{{ $row['client']->plan?->name ?? 'No plan' }}</div>
                                    </td>
                                    <td class="px-3 py-2"><flux:checkbox wire:model.live="attendees.{{ $clientId }}.attended" /></td>
                                    <td class="px-3 py-2"><flux:input wire:model.live.debounce.400ms="attendees.{{ $clientId }}.override" type="number" step="0.01" min="0" placeholder="Plan rate" class="w-28" /></td>
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        @if (! $row['attended']) <span class="text-zinc-400">No-show</span>
                                        @elseif ($row['error']) <span class="text-xs text-red-600 dark:text-red-400">{{ $row['error'] }}</span>
                                        @elseif ($row['total'] == 0) <span class="text-zinc-500">Included</span>
                                        @else {{ money($row['total']) }} <div class="text-xs text-zinc-500">{{ money($row['subtotal']) }} + {{ money($row['gst']) }} GST</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right"><flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeClient({{ $clientId }})" /></td>
                                </tr>
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
            </div>
        </section>
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
                        </flux:table.row>
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
</div>
