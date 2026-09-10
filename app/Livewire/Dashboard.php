<?php

namespace App\Livewire;

use App\Enums\PlanType;
use App\Enums\SessionStatus;
use App\Enums\TransactionType;
use App\Models\Client;
use App\Models\TrainingSession;
use App\Models\WalletTransaction;
use App\Services\GstCalculator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render()
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $charges = WalletTransaction::query()->active()
            ->ofType(TransactionType::SessionCharge, TransactionType::MonthlyFee)
            ->inPeriod($from, $to);

        $payments = WalletTransaction::query()->active()
            ->ofType(TransactionType::Payment, TransactionType::Refund)
            ->inPeriod($from, $to);

        return view('livewire.dashboard', [
            'activeClients' => Client::query()->active()->count(),
            'sessionsThisMonth' => TrainingSession::query()
                ->where('status', SessionStatus::Completed->value)
                ->whereBetween('starts_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'revenueThisMonth' => (float) $charges->sum('subtotal'),
            'gstThisMonth' => (float) $charges->sum('gst_amount'),
            'paymentsThisMonth' => (float) $payments->sum('amount'),
            'lowWallets' => $this->lowWallets(),
            'owing' => $this->owing(),
            'recent' => WalletTransaction::query()->active()->with('client')
                ->latest('transacted_on')->latest('id')->limit(8)->get(),
            'upcoming' => TrainingSession::query()->with(['service', 'attendees.client'])
                ->where('status', SessionStatus::Scheduled->value)
                ->where('starts_at', '>=', now()->startOfDay())
                ->orderBy('starts_at')->limit(5)->get(),
        ]);
    }

    /**
     * Pay-as-you-go clients whose balance no longer covers their next session — which for
     * a family means one fare per member, not one fare.
     */
    private function lowWallets(): Collection
    {
        $gstRate = auth()->user()->effectiveGstRate();

        return Client::query()->active()->withBalance()
            ->whereHas('plan', fn ($q) => $q->whereIn('type', [PlanType::Wallet->value, PlanType::Family->value]))
            ->with('plan.rates', 'activeMembers')
            ->get()
            ->filter(function (Client $client) use ($gstRate) {
                $people = max(1, $client->isOnFamilyPlan() ? $client->activeMembers->count() : 1);
                $rate = $client->plan->rateFor($client->plan->rates->first()?->service_id ?? 0, $people);
                $unit = $rate?->unit_price ?? $client->plan->rates->where('headcount', 1)->min('unit_price');
                $threshold = $unit === null ? 0.0 : GstCalculator::totalWithGst((float) $unit * $people, $gstRate);

                return (float) $client->balance < $threshold;
            })
            ->sortBy('balance')
            ->values();
    }

    /**
     * Monthly clients with an unpaid balance.
     */
    private function owing(): Collection
    {
        return Client::query()->active()->withBalance()
            ->whereHas('plan', fn ($q) => $q->where('type', PlanType::Monthly->value))
            ->with('plan')
            ->get()
            ->filter(fn (Client $client) => (float) $client->balance < 0)
            ->sortBy('balance')
            ->values();
    }
}
