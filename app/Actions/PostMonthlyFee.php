<?php

namespace App\Actions;

use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Models\Client;
use App\Models\WalletTransaction;
use App\Services\GstCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Posts one month's membership fee (plus GST) to a monthly client's ledger.
 * Idempotent per client and billing period: a second call returns the existing row.
 * The fee is dated on the plan's billing day unless a posting date is given.
 */
class PostMonthlyFee
{
    public function handle(Client $client, CarbonInterface|string|null $period = null, CarbonInterface|string|null $on = null): WalletTransaction
    {
        $period = CarbonImmutable::parse($period ?? today())->startOfMonth();
        $billingPeriod = $period->format('Y-m');
        $on = $on !== null ? CarbonImmutable::parse($on) : null;

        return DB::transaction(function () use ($client, $period, $billingPeriod, $on) {
            $client->loadMissing(['plan', 'trainer']);
            $plan = $client->plan;

            if ($plan === null || ! $plan->isMonthly()) {
                throw new BillingException("{$client->full_name} is not on a monthly plan.");
            }

            $existing = WalletTransaction::query()
                ->forTrainer($client->user_id)
                ->where('client_id', $client->id)
                ->ofType(TransactionType::MonthlyFee)
                ->where('billing_period', $billingPeriod)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $fee = round((float) $plan->monthly_fee, 2);
            $gst = GstCalculator::onExclusive($fee, $client->trainer->effectiveGstRate());
            $billingDay = min($plan->billing_day ?: 1, $period->daysInMonth);

            return WalletTransaction::create([
                'user_id' => $client->user_id,
                'client_id' => $client->id,
                'type' => TransactionType::MonthlyFee,
                'amount' => -round($fee + $gst, 2),
                'subtotal' => $fee,
                'gst_amount' => $gst,
                'transacted_on' => ($on ?? $period->day($billingDay))->toDateString(),
                'billing_period' => $billingPeriod,
                'description' => "{$plan->name} — {$period->format('F Y')}",
            ]);
        });
    }
}
