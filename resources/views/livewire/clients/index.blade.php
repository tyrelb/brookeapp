<div>
    <x-page-header title="Clients" subtitle="Manage your clients and their Fitness Wallets">
        <x-slot:actions>
            <flux:button :href="route('clients.create')" icon="user-plus" variant="primary" wire:navigate>Add client</flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 grid gap-3 md:grid-cols-4">
        <div class="md:col-span-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search by name or email" clearable />
        </div>
        <flux:select wire:model.live="status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="active">Active</flux:select.option>
            <flux:select.option value="inactive">Inactive</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="plan">
            <flux:select.option value="">All plans</flux:select.option>
            @foreach ($plans as $p)
                <flux:select.option value="{{ $p->id }}">{{ $p->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($clients->isEmpty())
        <x-empty-state title="No clients found" description="Add your first client to start tracking sessions and payments.">
            <flux:button :href="route('clients.create')" variant="primary" wire:navigate>Add client</flux:button>
        </x-empty-state>
    @else
        <flux:table :paginate="$clients">
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Plan</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column align="end">Balance</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($clients as $client)
                    <flux:table.row :key="$client->id">
                        <flux:table.cell variant="strong">
                            <flux:link :href="route('clients.show', $client)" wire:navigate>{{ $client->full_name }}</flux:link>
                            @if ($client->email)
                                <div class="text-xs font-normal text-zinc-500">{{ $client->email }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($client->plan)
                                {{ $client->plan->name }}
                                <flux:badge size="sm" class="ml-1" :color="$client->plan->isMonthly() ? 'purple' : 'teal'">{{ $client->plan->isMonthly() ? 'Monthly' : 'Wallet' }}</flux:badge>
                            @else
                                <span class="text-zinc-400">No plan</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$client->isActive() ? 'green' : 'zinc'">{{ $client->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end"><x-money :amount="$client->balance" class="font-medium" /></flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" :href="route('clients.show', $client)" wire:navigate>Open</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
