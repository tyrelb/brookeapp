<div class="max-w-2xl">
    <x-page-header :title="$client ? 'Edit '.$client->full_name : 'Add client'" />

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="first_name" label="First name" required autofocus />
            <flux:input wire:model="last_name" label="Last name" />
            <flux:input wire:model="email" label="Email" type="email" description="Used for Phase 2 session emails and the client wallet link." />
            <flux:input wire:model="phone" label="Phone" type="tel" />
        </div>

        <flux:select wire:model="plan_id" label="Plan" description="Decides how sessions are billed: included in a monthly fee, or deducted from the Fitness Wallet.">
            <flux:select.option value="">No plan yet</flux:select.option>
            @foreach ($plans as $plan)
                <flux:select.option value="{{ $plan->id }}">{{ $plan->name }} — {{ $plan->isMonthly() ? money($plan->monthly_fee).'/month' : 'pay-as-you-go' }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:select wire:model="status" label="Status">
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Inactive</flux:select.option>
            </flux:select>
            <flux:input wire:model="started_at" label="Client since" type="date" description="Monthly fees are not posted for months before this date." />
        </div>

        <flux:textarea wire:model="notes" label="Notes" rows="4" placeholder="Goals, injuries, preferences…" />

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">{{ $client ? 'Save changes' : 'Add client' }}</flux:button>
            <flux:button :href="$client ? route('clients.show', $client) : route('clients.index')" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
