<?php

namespace App\Livewire\Admin\Trainers;

use App\Models\Scopes\TrainerScope;
use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Trainers')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $filter = ''; // '' | unverified | suspended | admins

    /** Runs on every request, not only the first: an admin demoted mid-session loses access at once. */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $trainers = User::query()
            ->withCount([
                'clients' => fn ($q) => $q->withoutGlobalScope(TrainerScope::class),
                'trainingSessions' => fn ($q) => $q->withoutGlobalScope(TrainerScope::class),
            ])
            ->when($this->search !== '', function ($q) {
                $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")
                    ->orWhere('business_name', 'like', "%{$this->search}%"));
            })
            ->when($this->filter === 'unverified', fn ($q) => $q->whereNull('email_verified_at'))
            ->when($this->filter === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when($this->filter === 'admins', fn ($q) => $q->where('is_admin', true))
            ->latest()
            ->paginate(25);

        return view('livewire.admin.trainers.index', ['trainers' => $trainers]);
    }
}
