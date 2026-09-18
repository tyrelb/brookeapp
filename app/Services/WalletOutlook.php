<?php

namespace App\Services;

use App\Models\Client;

/**
 * How far a Fitness Wallet still goes: what one session costs this client, how many
 * of those the balance covers, and whether that is few enough to chase.
 *
 * This lived in three places that quietly disagreed — the dashboard's "running low"
 * list, the client's wallet page and its Blade template — so it lives here now.
 */
class WalletOutlook
{
    /** How many fares one session costs: a family pays one per attending member. */
    public static function people(Client $client): int
    {
        if (! $client->isOnFamilyPlan()) {
            return 1;
        }

        $members = $client->relationLoaded('activeMembers')
            ? $client->activeMembers->count()
            : $client->activeMembers()->count();

        return max(1, $members);
    }

    /**
     * What one session costs this client, GST included — for a family, the whole
     * household's fare, not one person's. Null when the plan cannot price a session:
     * a monthly membership (sessions are included) or a plan with no rates set.
     *
     * Pass $gstRate to avoid loading the trainer once per client in a list.
     */
    public static function sessionCost(Client $client, ?float $gstRate = null): ?float
    {
        $plan = $client->plan;

        if (! $plan || ! $plan->usesWallet()) {
            return null;
        }

        $people = self::people($client);
        $serviceId = $plan->mainServiceId();

        $unit = $serviceId === null ? null : $plan->rateFor($serviceId, $people)?->unit_price;
        $unit ??= $plan->rates->where('headcount', 1)->min('unit_price');

        if ($unit === null) {
            return null;
        }

        if ($gstRate === null) {
            $client->loadMissing('trainer');
            $gstRate = $client->trainer->effectiveGstRate();
        }

        return GstCalculator::totalWithGst((float) $unit * $people, $gstRate);
    }

    /**
     * Whole sessions the balance still covers. Null when a session cannot be priced;
     * zero once the wallet is empty or overdrawn.
     */
    public static function sessionsRemaining(Client $client, float $balance, ?float $gstRate = null): ?int
    {
        $cost = self::sessionCost($client, $gstRate);

        if ($cost === null || $cost <= 0) {
            return null;
        }

        return max(0, (int) floor($balance / $cost));
    }

    /** Less than one session left — the trainer should ask for a top-up. */
    public static function isRunningLow(Client $client, float $balance, ?float $gstRate = null): bool
    {
        $cost = self::sessionCost($client, $gstRate);

        return $cost !== null && $balance < $cost;
    }
}
