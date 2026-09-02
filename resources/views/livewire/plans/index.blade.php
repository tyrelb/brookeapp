<div>
    <x-page-header title="Plans &amp; pricing" subtitle="Each client is on one plan. Monthly plans include sessions; pay-as-you-go plans deduct per-person rates from the Fitness Wallet.">
        <x-slot:actions>
            <flux:button :href="route('services.index')" wire:navigate>Services</flux:button>
            <flux:button :href="route('plans.create')" icon="plus" variant="primary" wire:navigate>Add plan</flux:button>
        </x-slot:actions>
    </x-page-header>

    @if ($plans->isEmpty())
        <x-empty-state title="No plans yet" description='Create a pay-as-you-go plan with your single and partner rates, or a monthly membership.'>
            <flux:button :href="route('plans.create')" variant="primary" wire:navigate>Add plan</flux:button>
        </x-empty-state>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($plans as $plan)
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900 {{ $plan->active ? '' : 'opacity-60' }}" wire:key="plan-{{ $plan->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <flux:heading>{{ $plan->name }}</flux:heading>
                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                <flux:badge size="sm" :color="$plan->isMonthly() ? 'purple' : 'teal'">{{ $plan->isMonthly() ? 'Monthly' : 'Pay-as-you-go' }}</flux:badge>
                                @unless ($plan->active)
                                    <flux:badge size="sm" color="zinc">Inactive</flux:badge>
                                @endunless
                                <span class="text-xs text-zinc-500">{{ $plan->clients_count }} {{ Str::plural('client', $plan->clients_count) }}</span>
                            </div>
                        </div>
                        <div class="flex gap-1">
                            <flux:button size="sm" :href="route('plans.edit', $plan)" wire:navigate>Edit</flux:button>
                            @if ($plan->clients_count === 0)
                                <flux:button size="sm" variant="ghost" wire:click="delete({{ $plan->id }})" wire:confirm="Delete {{ $plan->name }}?">Delete</flux:button>
                            @endif
                        </div>
                    </div>

                    @if ($plan->description)
                        <flux:text class="mt-2 text-sm">{{ $plan->description }}</flux:text>
                    @endif

                    <div class="mt-4 text-sm">
                        @if ($plan->isMonthly())
                            <div class="text-lg font-semibold">{{ money($plan->monthly_fee) }} <span class="text-sm font-normal text-zinc-500">+ GST per month, billed on day {{ $plan->billing_day ?? 1 }}</span></div>
                            <div class="text-xs text-zinc-500">All sessions included.</div>
                        @else
                            @php($byService = $plan->rates->groupBy('service_id'))
                            @if ($byService->isEmpty())
                                <flux:text class="text-sm text-amber-600">No rates set yet — sessions can't be charged until you add some.</flux:text>
                            @else
                                <table class="w-full text-sm">
                                    <thead class="text-xs text-zinc-500">
                                        <tr><th class="py-1 text-left font-normal">Service</th>
                                        @for ($h = 1; $h <= \App\Models\Plan::MAX_HEADCOUNT; $h++)
                                            <th class="py-1 text-right font-normal">{{ \App\Models\Plan::headcountLabel($h) }}</th>
                                        @endfor
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($byService as $rates)
                                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                                <td class="py-1">{{ $rates->first()->service->name }}</td>
                                                @for ($h = 1; $h <= \App\Models\Plan::MAX_HEADCOUNT; $h++)
                                                    @php($rate = $rates->firstWhere('headcount', $h))
                                                    <td class="py-1 text-right tabular-nums">{{ $rate ? money($rate->unit_price) : '—' }}</td>
                                                @endfor
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <div class="mt-1 text-xs text-zinc-500">Per person, before GST. Missing tiers fall back to the next lower tier.</div>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
