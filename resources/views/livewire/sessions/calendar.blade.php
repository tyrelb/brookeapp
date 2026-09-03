<div class="space-y-4">
    <x-page-header title="Calendar" :subtitle="$title">
        <x-slot:actions>
            <flux:button.group>
                <flux:button icon="chevron-left" wire:click="previous" />
                <flux:button wire:click="today">Today</flux:button>
                <flux:button icon="chevron-right" wire:click="next" />
            </flux:button.group>
            <flux:button.group>
                <flux:button :variant="$view === 'month' ? 'primary' : 'outline'" wire:click="setView('month')">Month</flux:button>
                <flux:button :variant="$view === 'week' ? 'primary' : 'outline'" wire:click="setView('week')">Week</flux:button>
            </flux:button.group>
            <flux:button :href="route('sessions.book', ['date' => $date])" icon="calendar" variant="primary" wire:navigate>Book session</flux:button>
        </x-slot:actions>
    </x-page-header>

    @php($today = today()->toDateString())

    @if ($view === 'month')
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid min-w-[840px] grid-cols-7 border-b border-zinc-200 text-xs font-medium text-zinc-500 dark:border-zinc-700">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow)
                    <div class="px-2 py-2">{{ $dow }}</div>
                @endforeach
            </div>
            @foreach ($weeks as $week)
                <div class="grid min-w-[840px] grid-cols-7 border-b border-zinc-100 last:border-b-0 dark:border-zinc-800">
                    @foreach ($week as $day)
                        @php($key = $day->toDateString())
                        <div class="group min-h-28 border-r border-zinc-100 p-1.5 last:border-r-0 dark:border-zinc-800 {{ $day->month !== $month ? 'bg-zinc-50/60 dark:bg-zinc-950/40' : '' }}" wire:key="d-{{ $key }}">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex size-6 items-center justify-center rounded-full text-xs {{ $key === $today ? 'bg-[var(--color-accent)] font-semibold text-white' : ($day->month !== $month ? 'text-zinc-400' : 'text-zinc-700 dark:text-zinc-300') }}">{{ $day->day }}</span>
                                <a href="{{ route('sessions.book', ['date' => $key]) }}" wire:navigate class="rounded p-0.5 text-zinc-400 opacity-0 hover:text-[var(--color-accent)] group-hover:opacity-100" title="Book on {{ $day->format('M j') }}">
                                    <flux:icon.plus class="size-4" />
                                </a>
                            </div>
                            <div class="mt-1 space-y-1">
                                @foreach ($sessions->get($key, collect()) as $session)
                                    <a href="{{ route('sessions.show', $session) }}" wire:navigate wire:key="s-{{ $session->id }}"
                                       class="block truncate rounded px-1.5 py-0.5 text-xs leading-5 {{ match ($session->status->value) {
                                           'completed' => 'bg-green-50 text-green-800 dark:bg-green-900/40 dark:text-green-200',
                                           'cancelled' => 'bg-zinc-100 text-zinc-500 line-through dark:bg-zinc-800',
                                           default => 'bg-[var(--color-accent)]/10 text-[var(--color-accent-content)] dark:text-zinc-100',
                                       } }}"
                                       title="{{ $session->service->name }} · {{ $session->attendees->pluck('client.full_name')->join(', ') }}">
                                        <span class="font-medium">{{ $session->starts_at->format('g:i') }}</span>@if ($session->isInSeries())<span title="Repeats">↻</span>@endif
                                        {{ $session->attendees->pluck('client.first_name')->join(', ') ?: $session->service->name }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @else
        <div class="grid gap-3 lg:grid-cols-7">
            @foreach ($days as $day)
                @php($key = $day->toDateString())
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 {{ $key === $today ? 'ring-2 ring-[var(--color-accent)]/40' : '' }}" wire:key="w-{{ $key }}">
                    <div class="flex items-center justify-between">
                        <div class="text-sm font-medium">{{ $day->format('D') }} <span class="text-zinc-500">{{ $day->format('M j') }}</span></div>
                        <a href="{{ route('sessions.book', ['date' => $key]) }}" wire:navigate class="text-zinc-400 hover:text-[var(--color-accent)]" title="Book"><flux:icon.plus class="size-4" /></a>
                    </div>
                    <div class="mt-2 space-y-2">
                        @forelse ($sessions->get($key, collect()) as $session)
                            <a href="{{ route('sessions.show', $session) }}" wire:navigate wire:key="ws-{{ $session->id }}" class="block rounded-lg border border-zinc-200 p-2 text-xs hover:border-[var(--color-accent)] dark:border-zinc-700 {{ $session->isCancelled() ? 'opacity-60 line-through' : '' }}">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold">{{ $session->starts_at->format('g:i a') }}</span>
                                    <flux:badge size="sm" :color="$session->status->color()">{{ $session->status->label() }}</flux:badge>
                                </div>
                                <div class="mt-0.5 text-zinc-600 dark:text-zinc-300">{{ $session->service->name }}</div>
                                <div class="mt-0.5 text-zinc-500">{{ $session->attendees->pluck('client.full_name')->join(', ') ?: 'No attendees yet' }}</div>
                            </a>
                        @empty
                            <div class="text-xs text-zinc-400">—</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="flex flex-wrap gap-4 text-xs text-zinc-500">
        <span><span class="inline-block size-2.5 rounded-sm bg-[var(--color-accent)]/30 align-middle"></span> Scheduled</span>
        <span><span class="inline-block size-2.5 rounded-sm bg-green-200 align-middle"></span> Completed</span>
        <span><span class="inline-block size-2.5 rounded-sm bg-zinc-200 align-middle"></span> Cancelled</span>
        <span>Click a session to open it, or the + on a day to book.</span>
    </div>
</div>
