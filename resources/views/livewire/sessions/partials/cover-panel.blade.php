{{-- A session where the trainer covered the gym's own clients: the gym pays her,
     she owes it nothing for the space, and no client wallet is involved. --}}
<section class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 dark:border-emerald-900 dark:bg-emerald-950/20">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading>Covering {{ $session->gym?->name ?? 'the gym' }}'s clients</flux:heading>
        <flux:badge color="emerald">{{ $session->coverPeople() }} {{ Str::plural('person', $session->coverPeople()) }}</flux:badge>
    </div>

    <flux:subheading class="mt-1">
        The gym is not charged for this session. It shows as a credit on the gym usage report.
    </flux:subheading>

    @if ($session->coverNames())
        <div class="mt-3 flex flex-wrap gap-1.5">
            @foreach ($session->coverNames() as $name)
                <flux:badge size="sm" color="zinc">{{ $name }}</flux:badge>
            @endforeach
        </div>
    @endif

    <div class="mt-4 border-t border-emerald-200 pt-3 text-sm dark:border-emerald-900">
        @if ($session->isCompleted())
            <div class="flex justify-between"><span class="text-zinc-600 dark:text-zinc-400">Fee (before GST)</span><span>{{ money($session->cover_subtotal) }}</span></div>
            @if ((float) $session->cover_gst_amount > 0)
                <div class="flex justify-between"><span class="text-zinc-600 dark:text-zinc-400">GST you charge ({{ number_format((float) $session->cover_gst_rate, 2) }}%)</span><span>{{ money($session->cover_gst_amount) }}</span></div>
            @endif
            <div class="mt-1 flex justify-between border-t border-emerald-200 pt-1 font-medium dark:border-emerald-900">
                <span>The gym owes you</span><span>{{ money($session->coverTotal()) }}</span>
            </div>
        @elseif ($session->gym && $session->gym->coverRateFor($session->coverPeople()) !== null)
            <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                <span>Will credit you</span>
                <span>{{ money($session->gym->coverRateFor($session->coverPeople())) }} before GST</span>
            </div>
        @else
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>
                    No cover rate is set for this group size.
                    <flux:link :href="route('settings.gyms')" wire:navigate>Set one in Settings → Gyms</flux:link>
                    before completing the session.
                </flux:callout.text>
            </flux:callout>
        @endif
    </div>

    @if ($session->isCompleted() && $session->notes)
        <flux:text class="mt-3 whitespace-pre-line text-sm">{{ $session->notes }}</flux:text>
    @endif
</section>
