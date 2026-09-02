<x-admin.layout title="Trainers" subtitle="Every account on the platform">
    <div class="grid gap-3 md:grid-cols-4">
        <div class="md:col-span-3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search by name, email or business" clearable />
        </div>
        <flux:select wire:model.live="filter">
            <flux:select.option value="">All accounts</flux:select.option>
            <flux:select.option value="unverified">Unverified email</flux:select.option>
            <flux:select.option value="suspended">Suspended</flux:select.option>
            <flux:select.option value="admins">Administrators</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$trainers">
        <flux:table.columns>
            <flux:table.column>Trainer</flux:table.column>
            <flux:table.column>Status</flux:table.column>
            <flux:table.column align="end">Clients</flux:table.column>
            <flux:table.column align="end">Sessions</flux:table.column>
            <flux:table.column>Last login</flux:table.column>
            <flux:table.column>Joined</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($trainers as $trainer)
                <flux:table.row :key="$trainer->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('admin.trainers.show', $trainer)" wire:navigate>{{ $trainer->name }}</flux:link>
                        <div class="text-xs font-normal text-zinc-500">{{ $trainer->email }}{{ $trainer->business_name ? ' · '.$trainer->business_name : '' }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($trainer->isSuspended())
                            <flux:badge size="sm" color="red">Suspended</flux:badge>
                        @elseif (! $trainer->hasVerifiedEmail())
                            <flux:badge size="sm" color="amber">Unverified</flux:badge>
                        @else
                            <flux:badge size="sm" color="green">Active</flux:badge>
                        @endif
                        @if ($trainer->isAdmin())
                            <flux:badge size="sm" color="purple">Admin</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $trainer->clients_count }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $trainer->training_sessions_count }}</flux:table.cell>
                    <flux:table.cell class="text-xs">{{ $trainer->last_login_at?->diffForHumans() ?? 'Never' }}</flux:table.cell>
                    <flux:table.cell class="text-xs">{{ $trainer->created_at->format('M j, Y') }}</flux:table.cell>
                    <flux:table.cell align="end"><flux:button size="sm" :href="route('admin.trainers.show', $trainer)" wire:navigate>Open</flux:button></flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</x-admin.layout>
