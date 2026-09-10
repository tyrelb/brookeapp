<div>
    <x-page-header title="Sessions" subtitle="Every session you've logged, and who came">
        <x-slot:actions>
            <flux:button :href="route('sessions.log.bulk')" icon="squares-plus" wire:navigate>Bulk log</flux:button>
            <flux:button :href="route('sessions.log')" icon="plus" variant="primary" wire:navigate>Log session</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($seriesModel)
        <flux:callout icon="arrow-path" class="mb-4">
            <flux:callout.text>Showing one repeat: {{ $seriesModel->describe() }}. <flux:link :href="route('sessions.index')" wire:navigate>Show all sessions</flux:link></flux:callout.text>
        </flux:callout>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="scheduled">Scheduled</flux:select.option>
            <flux:select.option value="completed">Completed</flux:select.option>
            <flux:select.option value="cancelled">Cancelled</flux:select.option>
        </flux:select>
        <flux:input wire:model.live="month" type="month" />
    </div>

    @if ($sessions->isEmpty())
        <x-empty-state title="No sessions yet" description="Log a session after training to charge attendees and build their history.">
            <flux:button :href="route('sessions.log')" variant="primary" wire:navigate>Log session</flux:button>
        </x-empty-state>
    @else
        <flux:table :paginate="$sessions">
            <flux:table.columns>
                <flux:table.column>When</flux:table.column>
                <flux:table.column>Service</flux:table.column>
                <flux:table.column>Attendees</flux:table.column>
                <flux:table.column>Tier</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column align="end">Charged</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($sessions as $session)
                    <flux:table.row :key="$session->id">
                        <flux:table.cell variant="strong">
                            <flux:link :href="route('sessions.show', $session)" wire:navigate>{{ $session->starts_at->format('D M j, Y') }}</flux:link>
                            <div class="text-xs font-normal text-zinc-500">{{ $session->starts_at->format('g:i a') }} · {{ $session->duration_minutes }} min</div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $session->service->name }}@if ($session->isInSeries()) <a href="{{ route('sessions.index', ['series' => $session->session_series_id]) }}" wire:navigate title="{{ $session->series?->describe() }}"><flux:icon.arrow-path class="inline size-3.5 text-zinc-400" /></a>@endif @if ($session->gym)<div class="text-xs text-zinc-500">{{ $session->gym->name }}</div>@endif</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">
                            @foreach ($session->attendees as $attendee)
                                <span class="{{ $attendee->attended ? '' : 'line-through text-zinc-400' }}">{{ $attendee->client->full_name }}</span>@if (! $loop->last), @endif
                            @endforeach
                        </flux:table.cell>
                        <flux:table.cell>{{ \App\Models\Plan::headcountLabel($session->headcount()) }} ({{ $session->headcount() }})</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$session->status->color()">{{ $session->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell align="end">{{ $session->isCompleted() ? money($session->attendees->sum('total')) : '' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
