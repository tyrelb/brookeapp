<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Models\Gym;

/**
 * What a gym owes the trainer for covering its own clients while the owner is away.
 *
 * This is the mirror image of SessionPricer: money coming in rather than going out.
 * The rate is per session by group size, and the GST is the TRAINER's — she is
 * invoicing the gym, so it is tax she collects and remits, never the gym's rate,
 * which is tax she pays and reclaims.
 *
 * As with SessionPricer, the preview on the form and the amount stored on the session
 * both come from here, so the number on the button is the number on the statement.
 */
class CoverFeePricer
{
    /**
     * @param  list<string>  $names  the gym's clients she trained
     * @return array{people: int, subtotal: float, gst: float, total: float, gst_rate: float}
     *
     * @throws BillingException
     */
    public function price(Gym $gym, array $names, float $trainerGstRate): array
    {
        $priced = $this->preview($gym, $names, $trainerGstRate);

        if ($priced['error'] !== null) {
            throw new BillingException($priced['error']);
        }

        unset($priced['error']);

        return $priced;
    }

    /**
     * The same arithmetic for a half-filled form, reporting problems instead of throwing.
     *
     * @param  list<string>  $names
     * @return array{people: int, subtotal: float, gst: float, total: float, gst_rate: float, error: ?string}
     */
    public function preview(Gym $gym, array $names, float $trainerGstRate): array
    {
        $people = count($names);
        $rate = $gym->coverRateFor($people);

        $error = match (true) {
            $people === 0 => 'Add the name of at least one person you trained.',
            $rate === null => "No cover rate is set for {$gym->name}. Add what they pay you in Settings → Gyms.",
            default => null,
        };

        $subtotal = round((float) $rate, 2);
        $gst = GstCalculator::onExclusive($subtotal, $trainerGstRate);

        return [
            'people' => $people,
            'subtotal' => $subtotal,
            'gst' => $gst,
            'total' => round($subtotal + $gst, 2),
            'gst_rate' => $trainerGstRate,
            'error' => $error,
        ];
    }
}
