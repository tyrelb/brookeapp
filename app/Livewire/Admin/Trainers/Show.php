<?php

namespace App\Livewire\Admin\Trainers;

use App\Models\Scopes\TrainerScope;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Password;
use Livewire\Component;

/**
 * Support view of one trainer. Shows account status and activity counts only;
 * the trainer's clients, ledgers and reports are never exposed here.
 */
class Show extends Component
{
    public User $user;

    public function mount(User $user): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->user = $user;
    }

    public function sendPasswordReset(): void
    {
        $status = Password::broker()->sendResetLink(['email' => $this->user->email]);

        Flux::toast(
            $status === Password::RESET_LINK_SENT ? "Password reset email sent to {$this->user->email}." : __($status),
            variant: $status === Password::RESET_LINK_SENT ? 'success' : 'danger',
        );
    }

    public function resendVerification(): void
    {
        if ($this->user->hasVerifiedEmail()) {
            Flux::toast('This email is already verified.', variant: 'warning');

            return;
        }

        $this->user->sendEmailVerificationNotification();
        Flux::toast("Verification email sent to {$this->user->email}.", variant: 'success');
    }

    public function markVerified(): void
    {
        if (! $this->user->hasVerifiedEmail()) {
            $this->user->markEmailAsVerified();
        }

        Flux::toast('Account activated: email marked as verified.', variant: 'success');
    }

    public function suspend(): void
    {
        if ($this->user->is(auth()->user())) {
            Flux::toast("You can't suspend your own account.", variant: 'danger');

            return;
        }

        $this->user->forceFill(['suspended_at' => now()])->save();
        Flux::toast('Account suspended. They will be logged out on their next request.', variant: 'success');
    }

    public function unsuspend(): void
    {
        $this->user->forceFill(['suspended_at' => null])->save();
        Flux::toast('Account reinstated.', variant: 'success');
    }

    public function toggleAdmin(): void
    {
        if ($this->user->is(auth()->user())) {
            Flux::toast("You can't change your own administrator access.", variant: 'danger');

            return;
        }

        $this->user->forceFill(['is_admin' => ! $this->user->is_admin])->save();
        Flux::toast($this->user->is_admin ? 'Administrator access granted.' : 'Administrator access revoked.', variant: 'success');
    }

    public function render()
    {
        $this->user->refresh();

        return view('livewire.admin.trainers.show', [
            'clientsCount' => $this->user->clients()->withoutGlobalScope(TrainerScope::class)->count(),
            'sessionsCount' => $this->user->trainingSessions()->withoutGlobalScope(TrainerScope::class)->count(),
            'plansCount' => $this->user->plans()->withoutGlobalScope(TrainerScope::class)->count(),
        ])->title($this->user->name);
    }
}
