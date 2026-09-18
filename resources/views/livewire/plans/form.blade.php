<div class="max-w-3xl">
    <x-page-header :title="$plan ? 'Edit '.$plan->name : 'Add plan'" />

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Plan name" placeholder="Standard pay-as-you-go" required autofocus />

        <flux:radio.group wire:model.live="type" label="How is this plan billed?">
            <flux:radio value="wallet" label="Pay-as-you-go (Fitness Wallet)" description="Clients deposit money; each session deducts a per-person rate plus GST." />
            <flux:radio value="family" label="Family (one shared Fitness Wallet)" description="Several named people on one wallet. Each family member who attends is charged the rate for the size of the group." />
            <flux:radio value="monthly" label="Monthly membership" description="A flat fee plus GST is posted each month; all sessions are included." />
        </flux:radio.group>

        @if ($type === 'monthly')
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="monthly_fee" label="Monthly fee (before GST)" type="number" step="0.01" min="0" placeholder="300.00" />
                <flux:input wire:model="billing_day" label="Billing day of month" type="number" min="1" max="28" description="1–28 so every month works." />
            </div>
        @else
            <flux:input wire:model="package_sessions" label="Package size (optional)" type="number" min="1" max="500" placeholder="50"
                description="How many sessions this plan is sold in. Used to suggest an amount when you request payment — leave blank if it isn't sold as a package." />

            <div>
                <flux:heading size="lg">Session rates</flux:heading>
                <flux:subheading>Per person, before GST. Leave a tier blank to fall back to the next lower tier (e.g. a Triple with no rate uses the Partner rate). Groups of five or more pay the Quad rate.</flux:subheading>

                @if ($services->isEmpty())
                    <flux:callout variant="warning" class="mt-3" icon="exclamation-triangle">
                        <flux:callout.heading>No active services</flux:callout.heading>
                        <flux:callout.text>Add a service first, then come back to set its rates. <flux:link :href="route('services.index')" wire:navigate>Manage services</flux:link></flux:callout.text>
                    </flux:callout>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-zinc-500">
                                    <th class="py-2 pr-3 font-normal">Service</th>
                                    @foreach ($headcounts as $h)
                                        <th class="py-2 pr-3 font-normal">{{ \App\Models\Plan::headcountLabel($h) }} ({{ $h }})</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($services as $service)
                                    <tr class="border-t border-zinc-100 dark:border-zinc-800" wire:key="rate-row-{{ $service->id }}">
                                        <td class="py-2 pr-3 font-medium">{{ $service->name }}</td>
                                        @foreach ($headcounts as $h)
                                            <td class="py-2 pr-3">
                                                <flux:input wire:model="rates.{{ $service->id }}.{{ $h }}" type="number" step="0.01" min="0" placeholder="—" class="w-28" />
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @error('rates.*.*') <flux:error name="rates">{{ $message }}</flux:error> @enderror
                @endif
            </div>
        @endif

        <flux:textarea wire:model="description" label="Description" rows="2" placeholder="Who is this plan for? (optional)" />

        <flux:checkbox wire:model="active" label="Active" description="Inactive plans can't be assigned to new clients." />

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">{{ $plan ? 'Save changes' : 'Create plan' }}</flux:button>
            <flux:button :href="route('plans.index')" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
