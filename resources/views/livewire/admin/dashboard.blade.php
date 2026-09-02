<x-admin.layout title="Platform administration" subtitle="Support trainers and watch sign-ups. Billing and client details stay private to each trainer.">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Trainers signed up" :value="$stats['trainers']" :hint="$stats['suspended'].' suspended'" />
        <x-stat-card label="New this month" :value="$stats['new_this_month']" :hint="'Last month: '.$stats['new_last_month']" />
        <x-stat-card label="Active in last 30 days" :value="$stats['active_30d']" :hint="$stats['verified'].' verified · '.$stats['unverified'].' unverified'" />
        <x-stat-card label="Sessions completed this month" :value="$stats['sessions_this_month']" :hint="$stats['sessions_total'].' all time · '.$stats['clients'].' clients on the platform'" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Trainer sign-ups, last 12 months</flux:heading>
            <table class="mt-4 w-full text-sm">
                <tbody>
                    @foreach ($signups as $row)
                        <tr class="border-t border-zinc-100 first:border-t-0 dark:border-zinc-800">
                            <td class="w-24 py-1.5 text-zinc-600 dark:text-zinc-300">{{ $row['label'] }}</td>
                            <td class="py-1.5">
                                <div class="h-3 rounded bg-[var(--color-accent)]/80" style="width: {{ max(2, round($row['count'] / $signupMax * 100)) }}%"></div>
                            </td>
                            <td class="w-10 py-1.5 text-right tabular-nums font-medium">{{ $row['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <div class="space-y-6">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading>Needs a hand</flux:heading>
                <flux:subheading>Registered over a day ago but never verified their email</flux:subheading>
                <div class="mt-3">
                    @forelse ($needsAttention as $trainer)
                        <div class="flex items-center justify-between gap-3 border-t border-zinc-100 py-2 text-sm first:border-t-0 dark:border-zinc-800">
                            <div class="min-w-0">
                                <flux:link :href="route('admin.trainers.show', $trainer)" wire:navigate>{{ $trainer->name }}</flux:link>
                                <div class="truncate text-xs text-zinc-500">{{ $trainer->email }} · joined {{ $trainer->created_at->diffForHumans() }}</div>
                            </div>
                            <flux:badge size="sm" color="amber">Unverified</flux:badge>
                        </div>
                    @empty
                        <flux:text class="text-sm">Everyone is verified.</flux:text>
                    @endforelse
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:heading>Newest trainers</flux:heading>
                    <flux:link :href="route('admin.trainers.index')" wire:navigate class="text-sm">All trainers</flux:link>
                </div>
                <div class="mt-3">
                    @forelse ($recentTrainers as $trainer)
                        <div class="flex items-center justify-between gap-3 border-t border-zinc-100 py-2 text-sm first:border-t-0 dark:border-zinc-800">
                            <div class="min-w-0">
                                <flux:link :href="route('admin.trainers.show', $trainer)" wire:navigate>{{ $trainer->name }}</flux:link>
                                <div class="truncate text-xs text-zinc-500">{{ $trainer->business_name ?: $trainer->email }}</div>
                            </div>
                            <span class="shrink-0 text-xs text-zinc-500">{{ $trainer->created_at->format('M j, Y') }}</span>
                        </div>
                    @empty
                        <flux:text class="text-sm">No trainers yet.</flux:text>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-admin.layout>
