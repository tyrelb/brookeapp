<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\PlatformStats;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Admin overview')]
class Dashboard extends Component
{
    /** Runs on every request, not only the first: an admin demoted mid-session loses access at once. */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function render(PlatformStats $stats)
    {
        $signups = $stats->signupsByMonth(12);

        return view('livewire.admin.dashboard', [
            'stats' => $stats->summary(),
            'signups' => $signups,
            'signupMax' => max(1, max(array_column($signups, 'count'))),
            'recentTrainers' => User::query()->where('is_admin', false)->latest()->limit(8)->get(),
            'needsAttention' => User::query()->where('is_admin', false)
                ->whereNull('email_verified_at')
                ->where('created_at', '<=', now()->subDay())
                ->latest()->limit(8)->get(),
        ]);
    }
}
