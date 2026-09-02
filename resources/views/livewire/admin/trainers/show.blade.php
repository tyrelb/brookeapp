<x-admin.layout :title="$user->name" :subtitle="$user->email">
    <div class="grid gap-6 lg:grid-cols-3">
        <section class="space-y-4 lg:col-span-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading>Account</flux:heading>
                    @if ($user->isSuspended())
                        <flux:badge size="sm" color="red">Suspended {{ $user->suspended_at->format('M j, Y') }}</flux:badge>
                    @elseif (! $user->hasVerifiedEmail())
                        <flux:badge size="sm" color="amber">Email not verified</flux:badge>
                    @else
                        <flux:badge size="sm" color="green">Active</flux:badge>
                    @endif
                    @if ($user->isAdmin())
                        <flux:badge size="sm" color="purple">Administrator</flux:badge>
                    @endif
                </div>
                <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-zinc-500">Business name</dt><dd>{{ $user->business_name ?: '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Phone</dt><dd>{{ $user->phone ?: '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Joined</dt><dd>{{ $user->created_at->format('M j, Y g:i a') }}</dd></div>
                    <div><dt class="text-zinc-500">Email verified</dt><dd>{{ $user->email_verified_at?->format('M j, Y g:i a') ?? 'Not yet' }}</dd></div>
                    <div><dt class="text-zinc-500">Last login</dt><dd>{{ $user->last_login_at?->format('M j, Y g:i a') ?? 'Never' }}</dd></div>
                    <div><dt class="text-zinc-500">GST registered</dt><dd>{{ $user->gst_registered ? 'Yes' : 'No' }}</dd></div>
                </dl>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-stat-card label="Clients" :value="$clientsCount" />
                <x-stat-card label="Sessions logged" :value="$sessionsCount" />
                <x-stat-card label="Plans" :value="$plansCount" />
            </div>

            <flux:callout icon="lock-closed">
                <flux:callout.text>Counts only. This trainer's clients, wallet balances, payments and reports are private and not visible to administrators.</flux:callout.text>
            </flux:callout>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading>Support actions</flux:heading>
            <div class="mt-4 flex flex-col gap-2">
                <flux:button icon="key" wire:click="sendPasswordReset" wire:confirm="Email a password reset link to {{ $user->email }}?">Send password reset email</flux:button>
                @unless ($user->hasVerifiedEmail())
                    <flux:button icon="envelope" wire:click="resendVerification">Resend verification email</flux:button>
                    <flux:button icon="check-badge" variant="primary" wire:click="markVerified" wire:confirm="Mark {{ $user->email }} as verified? This activates the account without them clicking the email link.">Activate account (mark verified)</flux:button>
                @endunless
                <flux:separator class="my-2" />
                @if ($user->isSuspended())
                    <flux:button icon="lock-open" wire:click="unsuspend">Reinstate account</flux:button>
                @else
                    <flux:button icon="no-symbol" variant="danger" wire:click="suspend" wire:confirm="Suspend {{ $user->name }}? They will be logged out and unable to log in until reinstated. Their data is kept.">Suspend account</flux:button>
                @endif
                <flux:button icon="shield-check" variant="ghost" wire:click="toggleAdmin" wire:confirm="{{ $user->isAdmin() ? 'Revoke' : 'Grant' }} administrator access for {{ $user->name }}?">{{ $user->isAdmin() ? 'Revoke administrator access' : 'Make administrator' }}</flux:button>
            </div>
        </section>
    </div>
</x-admin.layout>
