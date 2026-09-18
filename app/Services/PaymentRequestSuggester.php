<?php

namespace App\Services;

use App\Models\Client;

/**
 * What to put on a payment request before the trainer touches it.
 *
 * A monthly member is asked for their fee. A client on a package is asked for the
 * package: however many sessions it holds, at their single-person rate. A plan that
 * is neither — pay-as-you-go with no package size set — gets no suggestion, because
 * guessing a number the trainer never chose is worse than leaving the field empty.
 */
class PaymentRequestSuggester
{
    /**
     * @return array{lines: list<array<string, mixed>>, subtotal: float, gst: float, gst_rate: float, total: float}|null
     */
    public function for(Client $client): ?array
    {
        $plan = $client->plan;

        if (! $plan) {
            return null;
        }

        if ($plan->isMonthly()) {
            $fee = (float) $plan->monthly_fee;

            if ($fee <= 0) {
                return null;
            }

            return $this->totals($client, [[
                'description' => $plan->name.' — monthly fee',
                'quantity' => 1,
                'unit_price' => $fee,
                'amount' => round($fee, 2),
            ]]);
        }

        $unit = $plan->package_sessions ? $plan->packageUnitPrice() : null;

        if ($unit === null || $unit <= 0) {
            return null;
        }

        $sessions = (int) $plan->package_sessions;

        return $this->totals($client, [[
            'description' => $sessions.' '.str('session')->plural($sessions).' × '.money($unit),
            'quantity' => $sessions,
            'unit_price' => $unit,
            'amount' => round($sessions * $unit, 2),
        ]]);
    }

    /**
     * The lines to freeze onto an invoice for a subtotal the trainer has settled on.
     * Keeps the itemised breakdown when they accepted the suggestion, and falls back
     * to one plain line when they typed their own number — quantity × unit price would
     * no longer add up, and a wrong breakdown is worse than none.
     *
     * @return list<array<string, mixed>>
     */
    public function linesFor(Client $client, float $subtotal): array
    {
        $suggested = $this->for($client);

        if ($suggested && abs($suggested['subtotal'] - $subtotal) < 0.005) {
            return $suggested['lines'];
        }

        return [[
            'description' => $client->isOnMonthlyPlan()
                ? trim(($client->plan?->name ?? '').' — membership fee', ' —')
                : 'Fitness Wallet top-up',
            'quantity' => null,
            'unit_price' => null,
            'amount' => round($subtotal, 2),
        ]];
    }

    /**
     * GST is added on top, the way monthly fees and session rates are: everything the
     * trainer types anywhere in this app is before tax.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, subtotal: float, gst: float, gst_rate: float, total: float}
     */
    public function totals(Client $client, array $lines): array
    {
        $client->loadMissing('trainer');
        $rate = $client->trainer->effectiveGstRate();

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $gst = GstCalculator::onExclusive($subtotal, $rate);

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'gst' => $gst,
            'gst_rate' => $rate,
            'total' => round($subtotal + $gst, 2),
        ];
    }
}
