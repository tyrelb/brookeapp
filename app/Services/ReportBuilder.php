<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SessionStatus;
use App\Enums\TransactionType;
use App\Models\Client;
use App\Models\Plan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Produces the numbers behind the monthly and annual reports.
 *
 * Revenue is reported on an accrual basis (charges posted in the period) and money
 * received on a cash basis (payments in the period). GST is shown both ways so the
 * trainer and their accountant can pick the basis they remit on.
 *
 * Gym cover fees are accrual revenue too, earned on the day she trained the gym's
 * clients. They never reach the cash side: the gym settles by crediting the amount
 * against what she owes it, so no payment is ever received.
 */
class ReportBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function monthly(User $trainer, int $year, int $month): array
    {
        $from = CarbonImmutable::create($year, $month, 1)->startOfMonth();

        return $this->period($trainer, $from, $from->endOfMonth()) + [
            'label' => $from->format('F Y'),
        ];
    }

    /**
     * @return array{year: int, months: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function annual(User $trainer, int $year): array
    {
        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $months[] = $this->monthly($trainer, $year, $m);
        }

        $from = CarbonImmutable::create($year, 1, 1)->startOfYear();
        $totals = $this->period($trainer, $from, $from->endOfYear()) + ['label' => (string) $year];

        return ['year' => $year, 'months' => $months, 'totals' => $totals];
    }

    /**
     * @return array<string, mixed>
     */
    public function period(User $trainer, CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $charges = WalletTransaction::query()->forTrainer($trainer)->active()
            ->ofType(TransactionType::SessionCharge, TransactionType::MonthlyFee)
            ->inPeriod($fromDate, $toDate)
            ->selectRaw('type, count(*) as rows_count, sum(subtotal) as subtotal, sum(gst_amount) as gst')
            ->groupBy('type')
            ->get()
            ->keyBy(fn ($row) => $row->type->value);

        $sessionCharges = $charges->get(TransactionType::SessionCharge->value);
        $monthlyFees = $charges->get(TransactionType::MonthlyFee->value);

        $moneyRows = WalletTransaction::query()->forTrainer($trainer)->active()
            ->ofType(TransactionType::Payment, TransactionType::Refund)
            ->inPeriod($fromDate, $toDate)
            ->get();

        $payments = $moneyRows->where('type', TransactionType::Payment);
        $refunds = $moneyRows->where('type', TransactionType::Refund);

        $byMethod = [];
        foreach (PaymentMethod::cases() as $method) {
            $byMethod[$method->value] = round((float) $payments->where('payment_method', $method)->sum('amount'), 2);
        }

        $sessions = TrainingSession::query()->forTrainer($trainer)
            ->where('status', SessionStatus::Completed->value)
            ->whereBetween('starts_at', [$from->startOfDay(), $to->endOfDay()])
            ->with(['service', 'attendees.client.plan', 'attendees.members'])
            ->get();

        // Unticking a session on the gym statement means it was never invoiced, so it is
        // not revenue either. One switch, one meaning — which is what keeps this report
        // and the gym statement agreeing without either having to know about the other.
        $coverSessions = $sessions->filter(fn (TrainingSession $s) => $s->isCover() && $s->gym_billable);
        $coverRevenue = round((float) $coverSessions->sum('cover_subtotal'), 2);
        $coverGst = round((float) $coverSessions->sum('cover_gst_amount'), 2);

        $byService = [];
        foreach ($sessions as $session) {
            $tier = Plan::headcountLabel($session->headcount());
            $name = $session->service->name;
            $earned = $session->isCover()
                ? ($session->gym_billable ? (float) $session->cover_subtotal : 0.0)
                : (float) $session->attendees->where('attended', true)->sum('subtotal');
            $byService[$name] ??= ['sessions' => 0, 'attendances' => 0, 'revenue' => 0.0, 'tiers' => []];
            $byService[$name]['sessions']++;
            $byService[$name]['attendances'] += $session->headcount();
            $byService[$name]['revenue'] = round($byService[$name]['revenue'] + $earned, 2);
            $byService[$name]['tiers'][$tier] = ($byService[$name]['tiers'][$tier] ?? 0) + 1;
        }
        ksort($byService);

        $balances = Client::query()->forTrainer($trainer)
            ->withSum(['transactions as balance' => fn ($q) => $q->whereNull('voided_at')->where('transacted_on', '<=', $toDate)], 'amount')
            ->get()
            ->map(fn (Client $c) => round((float) $c->balance, 2));

        $sessionRevenue = round((float) ($sessionCharges->subtotal ?? 0), 2);
        $feeRevenue = round((float) ($monthlyFees->subtotal ?? 0), 2);
        $gstCharged = round((float) ($sessionCharges->gst ?? 0) + (float) ($monthlyFees->gst ?? 0), 2);
        $paymentsTotal = round((float) $payments->sum('amount'), 2);
        $refundsTotal = round((float) $refunds->sum('amount'), 2); // negative

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'sessions' => [
                'count' => $sessions->count(),
                'attendances' => (int) $sessions->sum(fn ($s) => $s->headcount()),
                'by_service' => $byService,
            ],
            'revenue' => [
                'sessions' => $sessionRevenue,
                'session_charges' => (int) ($sessionCharges->rows_count ?? 0),
                'monthly_fees' => $feeRevenue,
                'monthly_fee_count' => (int) ($monthlyFees->rows_count ?? 0),
                'cover_fees' => $coverRevenue,
                'cover_sessions' => $coverSessions->count(),
                'cover_gst' => $coverGst,
                'total' => round($sessionRevenue + $feeRevenue + $coverRevenue, 2),
                'gst' => round($gstCharged + $coverGst, 2),
                'total_with_gst' => round($sessionRevenue + $feeRevenue + $coverRevenue + $gstCharged + $coverGst, 2),
            ],
            'payments' => [
                'by_method' => $byMethod,
                'count' => $payments->count(),
                'total' => $paymentsTotal,
                'refunds' => $refundsTotal,
                'net' => round($paymentsTotal + $refundsTotal, 2),
                // Cash basis: cover fees are deliberately absent, no money changed hands.
                'gst_embedded' => round((float) $payments->sum('gst_amount') - (float) $refunds->sum('gst_amount'), 2),
            ],
            'balances' => [
                'prepaid' => round((float) $balances->filter(fn ($b) => $b > 0)->sum(), 2),
                'owing' => round((float) $balances->filter(fn ($b) => $b < 0)->sum(), 2),
            ],
        ];
    }
}
