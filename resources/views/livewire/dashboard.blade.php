<div class="flex h-full w-full flex-1 flex-col gap-6">
    <x-page-header title="Dashboard" :subtitle="now()->format('F Y')">
        <x-slot:actions>
            <flux:button :href="route('clients.create')" icon="user-plus" wire:navigate>Add client</flux:button>
            <flux:button :href="route('sessions.log')" icon="plus" variant="primary" wire:navigate>Log session</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Active clients" :value="$activeClients" />
        <x-stat-card label="Sessions completed this month" :value="$sessionsThisMonth" />
        <x-stat-card label="Revenue this month (before GST)" :value="money($revenueThisMonth)" :hint="'GST charged: '.money($gstThisMonth)" />
        <x-stat-card label="Payments received this month" :value="money($paymentsThisMonth)" hint="Net of refunds" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Fitness Wallets running low</flux:heading>
            <flux:subheading>Pay-as-you-go clients with less than one single session left</flux:subheading>
            <div class="mt-4">
                @forelse ($lowWallets as $client)
                    <div class="flex items-center justify-between border-t border-zinc-100 py-2 first:border-t-0 dark:border-zinc-800">
                        <flux:link :href="route('clients.show', $client)" wire:navigate>{{ $client->full_name }}</flux:link>
                        <x-money :amount="$client->balance" class="font-medium" />
                    </div>
                @empty
                    <flux:text class="text-sm">Everyone has credit on hand.</flux:text>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Monthly members with a balance owing</flux:heading>
            <flux:subheading>Fees posted but not yet paid</flux:subheading>
            <div class="mt-4">
                @forelse ($owing as $client)
                    <div class="flex items-center justify-between border-t border-zinc-100 py-2 first:border-t-0 dark:border-zinc-800">
                        <flux:link :href="route('clients.show', $client)" wire:navigate>{{ $client->full_name }}</flux:link>
                        <x-money :amount="$client->balance" class="font-medium" />
                    </div>
                @empty
                    <flux:text class="text-sm">All monthly fees are paid up.</flux:text>
                @endforelse
            </div>
        </section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:heading>Recent activity</flux:heading>
                <flux:link :href="route('sessions.index')" wire:navigate class="text-sm">All sessions</flux:link>
            </div>
            <div class="mt-4">
                @forelse ($recent as $tx)
                    <div class="flex items-center justify-between gap-3 border-t border-zinc-100 py-2 text-sm first:border-t-0 dark:border-zinc-800">
                        <div class="min-w-0">
                            <flux:link :href="route('clients.show', $tx->client)" wire:navigate>{{ $tx->client->full_name }}</flux:link>
                            <div class="truncate text-xs text-zinc-500">{{ $tx->transacted_on->format('M j') }} · {{ $tx->type->label() }} · {{ $tx->description }}</div>
                        </div>
                        <x-money :amount="$tx->amount" signed class="shrink-0" />
                    </div>
                @empty
                    <flux:text class="text-sm">No wallet activity yet. Record a payment or log a session to get started.</flux:text>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Upcoming scheduled sessions</flux:heading>
            <div class="mt-4">
                @forelse ($upcoming as $session)
                    <div class="flex items-center justify-between gap-3 border-t border-zinc-100 py-2 text-sm first:border-t-0 dark:border-zinc-800">
                        <div class="min-w-0">
                            <flux:link :href="route('sessions.show', $session)" wire:navigate>{{ $session->starts_at->format('D M j, g:i a') }}</flux:link>
                            <div class="truncate text-xs text-zinc-500">{{ $session->service->name }} · {{ $session->attendees->pluck('client.full_name')->join(', ') ?: 'No attendees yet' }}</div>
                        </div>
                        <flux:badge size="sm" :color="$session->status->color()">{{ $session->status->label() }}</flux:badge>
                    </div>
                @empty
                    <flux:text class="text-sm">Nothing scheduled. Scheduling and calendar invites arrive in Phase 2.</flux:text>
                @endforelse
            </div>
        </section>
    </div>
</div>
