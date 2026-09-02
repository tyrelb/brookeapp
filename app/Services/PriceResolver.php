<?php

namespace App\Services;

use App\Exceptions\MissingRateException;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Service;

/**
 * Decides the before-tax price one attendee pays for a session.
 */
class PriceResolver
{
    /**
     * @param  int  $headcount  number of people who attended the session
     * @param  float|null  $override  explicit before-tax price set by the trainer for this attendee
     *
     * @throws MissingRateException when a pay-as-you-go client has no usable rate
     */
    public function forAttendee(Client $client, Service $service, int $headcount, ?float $override = null): float
    {
        if ($override !== null) {
            return round($override, 2);
        }

        $plan = $client->plan;

        if ($plan === null) {
            throw new MissingRateException("{$client->full_name} has no plan assigned. Assign a plan or enter a price override.");
        }

        if ($plan->isMonthly()) {
            return 0.0; // included in the monthly fee
        }

        $plan->loadMissing('rates');
        $rate = $plan->rateFor($service, max(1, $headcount));

        if ($rate === null) {
            $tier = Plan::headcountLabel($headcount);

            throw new MissingRateException("No {$tier} rate is set for {$service->name} on the \"{$plan->name}\" plan.");
        }

        return round((float) $rate->unit_price, 2);
    }
}
