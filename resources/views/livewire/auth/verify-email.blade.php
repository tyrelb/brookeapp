<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        // Three resends per ten minutes, so the button cannot be used to flood an inbox.
        $sent = RateLimiter::attempt('verify-email:'.Auth::id(), 3, function () {
            Auth::user()->sendEmailVerificationNotification();
        }, 600);

        Session::flash('status', $sent ? 'verification-link-sent' : 'verification-throttled');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="mt-4 flex flex-col gap-6">
    <div class="text-center text-sm text-gray-600">
        {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="font-medium text-center text-sm text-green-600">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @elseif (session('status') == 'verification-throttled')
        <div class="font-medium text-center text-sm text-amber-600">
            {{ __('A link was sent a moment ago. Please check your inbox, or wait a few minutes before asking again.') }}
        </div>
    @endif

    <div class="flex flex-col items-center justify-between space-y-3">
        <flux:button wire:click="sendVerification" variant="primary" class="w-full">
            {{ __('Resend verification email') }}
        </flux:button>

        <button
            wire:click="logout"
            type="submit"
            class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
            {{ __('Log out') }}
        </button>
    </div>
</div>
