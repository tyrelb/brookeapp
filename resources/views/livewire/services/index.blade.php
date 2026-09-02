<div>
    <x-page-header title="Services" subtitle="The kinds of sessions you run. Prices are set per plan.">
        <x-slot:actions>
            <flux:button :href="route('plans.index')" wire:navigate>Plans &amp; pricing</flux:button>
            <flux:button icon="plus" variant="primary" wire:click="create">Add service</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($services->isEmpty())
        <x-empty-state title="No services yet" description='Start with something like "Personal Training (60 min)". Then set prices on a plan.'>
            <flux:button variant="primary" wire:click="create">Add service</flux:button>
        </x-empty-state>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Service</flux:table.column>
                <flux:table.column>Duration</flux:table.column>
                <flux:table.column>Sessions logged</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($services as $service)
                    <flux:table.row :key="$service->id">
                        <flux:table.cell variant="strong">{{ $service->name }}</flux:table.cell>
                        <flux:table.cell>{{ $service->duration_minutes }} min</flux:table.cell>
                        <flux:table.cell>{{ $service->training_sessions_count }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$service->active ? 'green' : 'zinc'">{{ $service->active ? 'Active' : 'Inactive' }}</flux:badge></flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" wire:click="edit({{ $service->id }})">Edit</flux:button>
                            @if ($service->training_sessions_count === 0)
                                <flux:button size="sm" variant="ghost" wire:click="delete({{ $service->id }})" wire:confirm="Delete {{ $service->name }}?">Delete</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="service-form" class="md:w-96">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit service' : 'Add service' }}</flux:heading>
            <flux:input wire:model="name" label="Name" placeholder="Personal Training (60 min)" />
            <flux:input wire:model="duration_minutes" label="Default duration (minutes)" type="number" min="5" max="480" />
            <flux:checkbox wire:model="active" label="Active" description="Inactive services are hidden when logging sessions." />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
