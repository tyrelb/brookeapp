<div class="max-w-2xl">
    <x-page-header :title="$client ? 'Edit '.$client->full_name : 'Add client'" />

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="first_name" :label="$this->isFamilyPlan() ? 'Family name' : 'First name'" required autofocus />
            <flux:input wire:model="last_name" label="Last name" />
            <flux:input wire:model="email" label="Email" type="email" description="Used for Phase 2 session emails and the client wallet link." />
            <flux:input wire:model="phone" label="Phone" type="tel" />
        </div>

        <flux:select wire:model.live="plan_id" label="Plan" description="Decides how sessions are billed: included in a monthly fee, or deducted from the Fitness Wallet.">
            <flux:select.option value="">No plan yet</flux:select.option>
            @foreach ($plans as $plan)
                <flux:select.option value="{{ $plan->id }}">{{ $plan->name }} — {{ $plan->isMonthly() ? money($plan->monthly_fee).'/month' : ($plan->isFamily() ? 'family, pay-as-you-go' : 'pay-as-you-go') }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->isFamilyPlan())
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading>Family members</flux:heading>
                <flux:subheading>Everyone who trains on this one wallet, up to {{ \App\Models\Client::MAX_MEMBERS }}. When you log a session you tick which of them turned up, and the wallet is charged for those people.</flux:subheading>

                <div class="mt-3 space-y-2">
                    @foreach ($members as $index => $member)
                        <div class="flex items-center gap-2" wire:key="member-{{ $index }}-{{ $member['id'] ?? 'new' }}">
                            <flux:input wire:model.live.debounce.500ms="members.{{ $index }}.name" placeholder="{{ $index === 0 ? 'Mom' : ($index === 1 ? 'Dad' : 'Name') }}" class="flex-1" />
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeMember({{ $index }})" type="button" aria-label="Remove member" />
                        </div>
                    @endforeach
                </div>

                @error('members') <flux:error name="members">{{ $message }}</flux:error> @enderror

                @if (count($members) < \App\Models\Client::MAX_MEMBERS)
                    <flux:button size="sm" class="mt-3" icon="plus" wire:click="addMember" type="button">Add member</flux:button>
                @endif

                <flux:text class="mt-3 text-xs">Removing someone who has already trained keeps them on those past sessions; they just stop being offered on new ones.</flux:text>
            </div>
        @endif

        @if ($gyms->count() > 1)
            <flux:select wire:model="gym_id" label="Default gym" description="Pre-selected when you add this client to a session.">
                <flux:select.option value="">No default</flux:select.option>
                @foreach ($gyms as $gym)
                    <flux:select.option value="{{ $gym->id }}">{{ $gym->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

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
