<div class="space-y-3 max-sm:-mt-3 sm:space-y-4">
    {{-- On a phone the page header's rows of buttons shrink to one compact toolbar. --}}
    <div class="space-y-2 sm:hidden">
        <div class="flex items-center gap-2">
            <flux:heading size="lg" level="1" class="min-w-0 flex-1 truncate">{{ $shortTitle }}</flux:heading>
            <flux:button.group>
                <flux:button size="sm" icon="chevron-left" wire:click="previous" aria-label="Previous" />
                <flux:button size="sm" wire:click="today">Today</flux:button>
                <flux:button size="sm" icon="chevron-right" wire:click="next" aria-label="Next" />
            </flux:button.group>
            <flux:button size="sm" variant="primary" icon="plus" :href="route('sessions.book', ['date' => $date])" wire:navigate aria-label="Book session" />
        </div>
        <flux:button.group class="w-full">
            @foreach (['list' => 'List', 'day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $key => $label)
                <flux:button size="sm" class="flex-1" :variant="$view === $key ? 'primary' : 'outline'" wire:click="setView('{{ $key }}')">{{ $label }}</flux:button>
            @endforeach
        </flux:button.group>
    </div>

    <x-page-header title="Calendar" :subtitle="$title" class="max-sm:hidden">
        <x-slot:actions>
            <flux:button.group>
                <flux:button icon="chevron-left" wire:click="previous" />
                <flux:button wire:click="today">Today</flux:button>
                <flux:button icon="chevron-right" wire:click="next" />
            </flux:button.group>
            <flux:button.group>
                <flux:button :variant="$view === 'day' ? 'primary' : 'outline'" wire:click="setView('day')">Day</flux:button>
                <flux:button :variant="$view === 'week' ? 'primary' : 'outline'" wire:click="setView('week')">Week</flux:button>
                <flux:button :variant="$view === 'month' ? 'primary' : 'outline'" wire:click="setView('month')">Month</flux:button>
                <flux:button :variant="$view === 'list' ? 'primary' : 'outline'" wire:click="setView('list')">List</flux:button>
            </flux:button.group>
            <flux:button :href="route('sessions.book', ['date' => $date])" icon="calendar" variant="primary" wire:navigate>Book session</flux:button>
        </x-slot:actions>
    </x-page-header>

    @php($today = today()->toDateString())

    @if ($conflictCount > 0)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>{{ $conflictCount }} {{ Str::plural('session', $conflictCount) }} in this view {{ $conflictCount === 1 ? 'overlaps' : 'overlap' }} another booking. Overlaps are marked ⚠ — open one to reschedule or cancel it.</flux:callout.text>
        </flux:callout>
    @endif

    @if ($view === 'list')
        {{-- A phone's agenda: the days ahead that have something booked, each session a row to tap. --}}
        <div class="overflow-hidden border-y border-zinc-200 bg-white max-sm:-mx-6 sm:rounded-xl sm:border-x dark:border-zinc-700 dark:bg-zinc-900">
            @foreach ($listDays as $day)
                @php($key = $day->toDateString())
                @php($daySessions = $sessions->get($key, collect()))
                <section wire:key="ld-{{ $key }}" class="border-b border-zinc-200 last:border-b-0 dark:border-zinc-700">
                    <h3 class="flex items-center gap-2 border-b border-zinc-100 bg-zinc-50 px-4 py-1.5 text-[13px] font-semibold dark:border-zinc-800 dark:bg-zinc-800/60 {{ $key === $today ? 'text-[var(--color-accent-content)]' : 'text-zinc-700 dark:text-zinc-200' }}">
                        {{ $day->format('l') }} – {{ $day->format('M j') }}
                        @if ($key === $today)
                            <flux:badge size="sm" color="purple">Today</flux:badge>
                        @endif
                    </h3>
                    @if ($daySessions->isEmpty())
                        <p class="px-4 py-2.5 text-sm text-zinc-500">
                            Nothing booked today.
                            <a href="{{ route('sessions.book', ['date' => $key]) }}" wire:navigate class="font-medium text-[var(--color-accent)] hover:underline">Book a session</a>
                        </p>
                    @endif
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($daySessions as $session)
                            @php($clash = isset($conflicts[$session->id]))
                            @php($unlogged = $session->isScheduled() && $session->endsAt()->isPast())
                            <li wire:key="ls-{{ $session->id }}">
                                <button type="button" x-on:click="$flux.modal('session-sheet').show(); $wire.dispatch('open-session', { id: {{ $session->id }} })"
                                        class="flex w-full items-stretch gap-3 px-4 py-2.5 text-left hover:bg-zinc-50 active:bg-zinc-100 dark:hover:bg-zinc-800/50 dark:active:bg-zinc-800">
                                    <span class="w-1 shrink-0 rounded-full {{ match ($session->status->value) {
                                        'completed' => 'bg-green-500',
                                        'cancelled' => 'bg-zinc-300 dark:bg-zinc-600',
                                        default => 'bg-[var(--color-accent)]',
                                    } }}"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate leading-6 font-semibold {{ $session->isCancelled() ? 'text-zinc-400 line-through' : 'text-zinc-900 dark:text-zinc-50' }}">
                                            @if ($clash)<span class="text-amber-600 dark:text-amber-400" title="Overlaps another session">⚠</span>@endif
                                            {{ $session->displayName() }}
                                        </span>
                                        <span class="flex items-center gap-1 text-sm leading-5 text-zinc-500">
                                            @if ($session->gym)
                                                <flux:icon.map-pin variant="micro" class="shrink-0" />
                                                <span class="truncate">{{ $session->gym->name }}</span>
                                            @else
                                                <span class="truncate">{{ $session->service->name }}</span>
                                            @endif
                                            @if ($session->isInSeries())<span class="shrink-0" title="Repeats">↻</span>@endif
                                            @if ($unlogged)
                                                <span class="shrink-0 font-medium text-amber-700 dark:text-amber-400">· Not logged</span>
                                            @elseif ($session->isCompleted())
                                                <flux:icon.check variant="micro" class="shrink-0 text-green-600 dark:text-green-400" /><span class="sr-only">Logged</span>
                                            @elseif ($session->isCancelled())
                                                <span class="shrink-0">· Cancelled</span>
                                            @endif
                                        </span>
                                    </span>
                                    <span class="shrink-0 text-right text-sm tabular-nums">
                                        <span class="block leading-6 font-medium text-zinc-900 dark:text-zinc-100">{{ $session->starts_at->format('g:i a') }}</span>
                                        <span class="block leading-5 text-zinc-500">{{ $session->endsAt()->format('g:i a') }}</span>
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach

            @if ($sessions->isEmpty())
                <p class="px-4 py-6 text-center text-sm text-zinc-500">
                    Nothing booked for {{ $title }}.
                    <a href="{{ route('sessions.book', ['date' => $date]) }}" wire:navigate class="font-medium text-[var(--color-accent)] hover:underline">Book a session</a>
                </p>
            @endif

            <div class="border-t border-zinc-200 px-4 py-2 text-center dark:border-zinc-700">
                @if ($canShowMore)
                    <flux:button variant="ghost" size="sm" icon="chevron-down" wire:click="showMore">Show 2 more weeks</flux:button>
                @else
                    <flux:button variant="ghost" size="sm" icon-trailing="chevron-right" wire:click="next">Next 2 weeks</flux:button>
                @endif
            </div>
        </div>
    @elseif ($view === 'month')
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
                                <button type="button" wire:click="showDay('{{ $key }}')" title="See {{ $day->format('l, M j') }}" class="inline-flex size-6 items-center justify-center rounded-full text-xs hover:ring-2 hover:ring-[var(--color-accent)]/40 {{ $key === $today ? 'bg-[var(--color-accent)] font-semibold text-white' : ($day->month !== $month ? 'text-zinc-400' : 'text-zinc-700 dark:text-zinc-300') }}">{{ $day->day }}</button>
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
                                       } }} {{ isset($conflicts[$session->id]) ? 'ring-1 ring-amber-400 dark:ring-amber-500' : '' }}"
                                       title="{{ $session->service->name }} · {{ $session->attendees->pluck('client.full_name')->join(', ') }}{{ isset($conflicts[$session->id]) ? ' · overlaps another session' : '' }}">
                                        @if (isset($conflicts[$session->id]))<span class="text-amber-600 dark:text-amber-400" title="Overlaps another session">⚠</span>@endif
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
        {{-- Day and week: a 24-hour time grid, so the free time between sessions shows as space. --}}
        <div wire:key="grid-{{ $view }}-{{ $days[0]->toDateString() }}"
             x-data x-init="$el.scrollTop = Math.max(0, $refs.hours.offsetHeight / 24 * {{ $scrollHour }} - 12)"
             class="cal-grid h-[calc(100dvh-12.5rem)] min-h-96 overflow-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="{{ $view === 'week' ? 'min-w-[56rem]' : '' }}">
                <div class="sticky top-0 z-40 flex border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    @if ($view === 'week')
                        <div class="sticky left-0 z-10 w-14 shrink-0 border-r border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"></div>
                        @foreach ($days as $day)
                            @php($key = $day->toDateString())
                            <div class="group relative flex min-w-0 flex-1 items-center justify-center border-r border-zinc-100 py-2.5 last:border-r-0 dark:border-zinc-800 {{ $key === $today ? 'bg-[var(--color-accent)] text-white' : '' }}" wire:key="wh-{{ $key }}">
                                <button type="button" wire:click="showDay('{{ $key }}')" title="See {{ $day->format('l, M j') }}" class="text-sm font-medium hover:underline">{{ $day->format('D j') }}</button>
                                <a href="{{ route('sessions.book', ['date' => $key]) }}" wire:navigate class="absolute right-2 rounded p-0.5 opacity-0 group-hover:opacity-100 {{ $key === $today ? 'text-white/80 hover:text-white' : 'text-zinc-400 hover:text-[var(--color-accent)]' }}" title="Book on {{ $day->format('M j') }}">
                                    <flux:icon.plus class="size-4" />
                                </a>
                            </div>
                        @endforeach
                    @else
                        @php($key = $days[0]->toDateString())
                        @php($daySessions = $sessions->get($key, collect()))
                        @php($byStatus = $daySessions->countBy(fn ($s) => $s->status->value))
                        <div class="flex flex-1 flex-wrap items-center justify-between gap-2 px-4 py-3">
                            <div class="flex items-center gap-2 text-sm">
                                <span class="font-medium">{{ $days[0]->format('l') }}</span>
                                <span class="text-zinc-500">{{ $days[0]->format('M j') }}</span>
                                @if ($key === $today)
                                    <flux:badge size="sm" color="purple">Today</flux:badge>
                                @endif
                            </div>
                            <div class="text-xs text-zinc-500">
                                @if ($daySessions->isEmpty())
                                    No sessions on this day.
                                    <a href="{{ route('sessions.book', ['date' => $key]) }}" wire:navigate class="font-medium text-[var(--color-accent)] hover:underline">Book one</a>
                                @else
                                    {{ $daySessions->count() }} {{ Str::plural('session', $daySessions->count()) }}
                                    @foreach (['scheduled', 'completed', 'cancelled'] as $status)
                                        @if ($byStatus->get($status))
                                            · {{ $byStatus->get($status) }} {{ $status }}
                                        @endif
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex" x-ref="hours">
                    <div class="sticky left-0 z-30 w-14 shrink-0 border-r border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" aria-hidden="true">
                        @for ($hour = 0; $hour < 24; $hour++)
                            <div class="relative h-[var(--cal-hour)]">
                                @if ($hour > 0)
                                    <span class="absolute -top-2 right-2 text-[11px] leading-4 whitespace-nowrap text-zinc-500">{{ date('g A', mktime($hour, 0)) }}</span>
                                @endif
                            </div>
                        @endfor
                    </div>

                    @foreach ($days as $day)
                        @php($key = $day->toDateString())
                        <div class="relative min-w-0 flex-1 border-r border-zinc-100 last:border-r-0 dark:border-zinc-800" wire:key="wc-{{ $key }}">
                            @foreach ($halfHours as $time => $label)
                                <a href="{{ route('sessions.book', ['date' => $key, 'time' => $time], false) }}" wire:navigate tabindex="-1" aria-hidden="true" data-time="{{ $label }}" class="cal-slot {{ $loop->even ? 'cal-slot-half' : '' }}"></a>
                            @endforeach

                            @foreach ($sessions->get($key, collect()) as $session)
                                @php($place = $placements[$session->id])
                                @php($clash = isset($conflicts[$session->id]))
                                {{-- Under 45 minutes there is only room for one line, so it carries the start time. --}}
                                @php($compact = $session->duration_minutes < 45)
                                @php($who = $session->displayName())
                                @php($when = $session->starts_at->format('g:i a').' – '.$session->endsAt()->format('g:i a'))
                                <a href="{{ route('sessions.show', $session) }}" wire:navigate wire:key="gs-{{ $session->id }}"
                                   style="top: {{ $place['top'] }}%; height: calc({{ $place['height'] }}% - 1px); left: calc({{ $place['left'] }}% + 2px); width: calc({{ $place['width'] }}% - 4px);"
                                   title="{{ $when }} · {{ $session->service->name }} · {{ $who }}{{ $session->gym ? ' · '.$session->gym->name : '' }} · {{ $session->status->label() }}{{ $clash ? ' · overlaps another session' : '' }}"
                                   class="absolute z-10 min-h-5 overflow-hidden rounded-md bg-white shadow-xs hover:z-20 hover:shadow-md dark:bg-zinc-900 {{ $clash ? 'ring-2 ring-amber-400 dark:ring-amber-500' : '' }}">
                                    <div class="h-full border-l-4 px-1.5 text-xs leading-4 {{ $compact ? 'py-0.5' : 'py-1' }} {{ match ($session->status->value) {
                                        'completed' => 'border-green-500 bg-green-50 text-green-950 dark:bg-green-900/40 dark:text-green-50',
                                        'cancelled' => 'border-zinc-300 bg-zinc-100 text-zinc-500 line-through dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                        default => 'border-[var(--color-accent)] bg-[var(--color-accent)]/10 text-zinc-900 dark:bg-[var(--color-accent)]/25 dark:text-zinc-50',
                                    } }}">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0 truncate">
                                                @if ($clash)<span class="text-amber-600 dark:text-amber-400">⚠</span>@endif
                                                <span class="font-semibold">{{ $who }}</span>
                                                @if ($compact)
                                                    <span class="opacity-75">{{ $view === 'day' ? $when.' · '.$session->service->name : $session->starts_at->format('g:i a') }}</span>
                                                @endif
                                            </div>
                                            @if ($view === 'day')
                                                <flux:badge size="sm" :color="$session->status->color()" class="shrink-0">{{ $session->status->label() }}</flux:badge>
                                            @endif
                                        </div>
                                        @unless ($compact)
                                            <div class="truncate opacity-75">
                                                {{ $when }}@if ($session->isInSeries()) <span title="Repeats">↻</span>@endif
                                                @if ($view === 'day')
                                                    · {{ $session->service->name }}@if ($session->gym) · {{ $session->gym->name }}@endif
                                                @endif
                                            </div>
                                            @if ($session->duration_minutes >= 60)
                                                @if ($view === 'week')
                                                    <div class="truncate opacity-75">{{ $session->service->name }}</div>
                                                @elseif ($session->notes)
                                                    <div class="truncate opacity-60">{{ $session->notes }}</div>
                                                @endif
                                            @endif
                                        @endunless
                                    </div>
                                </a>
                            @endforeach

                            @if ($key === $today)
                                <div class="pointer-events-none absolute inset-x-0 z-20 border-t-2 border-red-500" style="top: {{ $nowOffset }}%">
                                    <div class="absolute -top-[5px] -left-1 size-2 rounded-full bg-red-500"></div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- On a phone the list speaks for itself; the legend would only push it down. --}}
    <div @class(['flex flex-wrap gap-4 text-xs text-zinc-500', 'max-sm:hidden' => $view === 'list'])>
        <span><span class="inline-block size-2.5 rounded-sm bg-[var(--color-accent)]/30 align-middle"></span> Scheduled</span>
        <span><span class="inline-block size-2.5 rounded-sm bg-green-200 align-middle"></span> Completed</span>
        <span><span class="inline-block size-2.5 rounded-sm bg-zinc-200 align-middle"></span> Cancelled</span>
        <span><span class="text-amber-600 dark:text-amber-400">⚠</span> Overlapping bookings</span>
        @if ($view === 'list')
            <span>Tap a session to log, edit or cancel it.</span>
        @elseif ($view === 'month')
            <span>Click a session to open it, a date to see that whole day, or the + on a day to book.</span>
        @else
            <span>Click a session to open it{{ $view === 'week' ? ", a day's heading to see that whole day," : '' }} or an empty time to book it.</span>
        @endif
    </div>

    <livewire:sessions.session-sheet key="session-sheet" />
</div>
