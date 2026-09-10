<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Gyms" subheading="Where you train and what each gym charges you. Up to three active gyms.">
        <div class="my-6 w-full space-y-4">
            @forelse ($gyms as $gym)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 {{ $gym->active ? '' : 'opacity-60' }}" wire:key="gym-{{ $gym->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading>{{ $gym->name }}</flux:heading>
                                @if ($gym->is_default) <flux:badge size="sm" color="purple">Default</flux:badge> @endif
                                @unless ($gym->active) <flux:badge size="sm" color="zinc">Inactive</flux:badge> @endunless
                            </div>
                            <div class="mt-1 text-sm text-zinc-500">
                                {{ $gym->billing_model->label() }}
                                @if ($gym->chargesMonthly()) · {{ money($gym->monthly_fee) }}/month @endif
                                @if ($gym->chargesUsage()) · {{ money($gym->rateFor(1)) }}/hr for 1, {{ money($gym->rateFor(2)) }}/hr for 2 … {{ money($gym->rateFor(10)) }}/hr for 10 @endif
                                · {{ $gym->charges_gst ? number_format((float) $gym->gst_rate, 2).'% GST' : 'no GST' }}
                            </div>
                            @if ($gym->chargesUsage() && $gym->rateFor(1) !== null)
                                {{-- This gym's real numbers, so it stays true if the card is edited. --}}
                                <div class="mt-1 text-xs text-zinc-500">A 90-minute session for 1 person costs {{ money($gym->chargeFor(1, 90)) }}.</div>
                            @endif
                            @if ($gym->coversSessions())
                                <div class="mt-1 text-sm text-emerald-700 dark:text-emerald-400">
                                    Pays you {{ money($gym->coverRateFor(1)) }} for 1, {{ money($gym->coverRateFor(2)) }} for 2 to cover their clients
                                </div>
                            @endif
                            <div class="mt-1 text-xs text-zinc-500">{{ $gym->training_sessions_count }} {{ Str::plural('session', $gym->training_sessions_count) }} logged here</div>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-1">
                            @if (! $gym->is_default)
                                <flux:button size="sm" variant="ghost" wire:click="makeDefault({{ $gym->id }})">Make default</flux:button>
                            @endif
                            <flux:button size="sm" wire:click="edit({{ $gym->id }})">Edit</flux:button>
                            @if ($gym->training_sessions_count === 0)
                                <flux:button size="sm" variant="ghost" wire:click="delete({{ $gym->id }})" wire:confirm="Remove {{ $gym->name }}?">Remove</flux:button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <x-empty-state title="No gyms yet" description="Add the gym you train at to track what it charges you by the hour and per month." />
            @endforelse

            @if (! $isFirst && $unassigned > 0)
                <flux:callout icon="exclamation-triangle" variant="warning">
                    <flux:callout.text>{{ $unassigned }} {{ Str::plural('session', $unassigned) }} {{ $unassigned === 1 ? 'has' : 'have' }} no gym. The Gym usage report can assign them to a gym for you, month by month.</flux:callout.text>
                </flux:callout>
            @endif

            <div>
                <flux:button icon="plus" variant="primary" wire:click="create">Add gym</flux:button>
            </div>
        </div>
    </x-settings.layout>

    <flux:modal name="gym-form" class="md:w-[36rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit gym' : 'Add gym' }}</flux:heading>

            <flux:input wire:model="name" label="Gym name" placeholder="Westside Athletic Club" />

            <flux:radio.group wire:model.live="billing_model" label="How does this gym charge you?">
                @foreach ($models as $model)
                    <flux:radio value="{{ $model->value }}" label="{{ $model->label() }}" description="{{ $model->description() }}" />
                @endforeach
            </flux:radio.group>

            @if ($billing_model !== 'usage')
                <flux:input wire:model="monthly_fee" label="Monthly rate (before GST)" type="number" step="0.01" min="0" placeholder="0.00" />
            @endif

            @if ($billing_model !== 'monthly')
                <div>
                    <flux:label>Hourly rate by group size (before GST)</flux:label>
                    <flux:description>Sessions bill pro-rata by length: 90 minutes costs one and a half times the hourly rate, 30 minutes half. Leave a size blank to use the next smaller size's rate. Groups over 10 use the 10-person rate.</flux:description>
                    <div class="mt-2 grid grid-cols-5 gap-2">
                        @foreach ($people as $n)
                            <flux:input wire:model="rates.{{ $n }}" type="number" step="0.01" min="0" placeholder="—">
                                <x-slot:iconLeading><span class="text-xs text-zinc-500">{{ $n }}</span></x-slot:iconLeading>
                            </flux:input>
                        @endforeach
                    </div>
                    @error('rates.1') <flux:error name="rates.1">{{ $message }}</flux:error> @enderror
                </div>
            @endif

            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:checkbox wire:model.live="covers_clients"
                    label="This gym pays me to cover its own clients"
                    description="For when the owner is away. You are paid per session by group size and owe the gym nothing for those sessions." />

                @if ($covers_clients)
                    <div class="mt-4">
                        <flux:label>What the gym pays you per session, by group size</flux:label>
                        <flux:description>Before <strong>your</strong> GST &mdash; this is money coming in, not a usage charge. Leave a size blank to use the next smaller size's rate.</flux:description>
                        <div class="mt-2 grid grid-cols-5 gap-2">
                            @foreach ($people as $n)
                                <flux:input wire:model="coverRates.{{ $n }}" type="number" step="0.01" min="0" placeholder="—">
                                    <x-slot:iconLeading><span class="text-xs text-zinc-500">{{ $n }}</span></x-slot:iconLeading>
                                </flux:input>
                            @endforeach
                        </div>
                        @error('coverRates.1') <flux:error name="coverRates.1">{{ $message }}</flux:error> @enderror
                    </div>
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:checkbox wire:model.live="charges_gst" label="This gym charges GST" />
                @if ($charges_gst)
                    <flux:input wire:model="gst_rate" label="GST rate (%)" type="number" step="0.01" min="0" max="30" />
                @endif
            </div>

            <flux:textarea wire:model="notes" label="Notes" rows="2" placeholder="Account number, contact, billing day… (optional)" />

            <div class="space-y-2">
                <flux:checkbox wire:model="is_default" label="Default gym for new sessions" />
                <flux:checkbox wire:model="active" label="Active" description="Inactive gyms can't be picked for new sessions." />
                @error('active') <flux:error name="active">{{ $message }}</flux:error> @enderror
                @if (! $editingId && $isFirst && $unassigned > 0)
                    <flux:checkbox wire:model="assignExisting" label="Assign my existing {{ $unassigned }} {{ Str::plural('session', $unassigned) }} to this gym" description="Untick if you trained elsewhere before now." />
                @endif
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save gym</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
