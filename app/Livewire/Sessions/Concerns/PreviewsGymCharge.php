<?php

namespace App\Livewire\Sessions\Concerns;

use App\Models\Gym;

/**
 * What the gym charges the trainer for the room. This is money going out, the opposite
 * direction from PreviewsCharges, which is what clients pay in — so it is deliberately
 * kept out of that shape and rendered well away from the client totals.
 *
 * Always before GST: the gym adds its GST once a month on the usage report, never per
 * session, and quoting a tax-inclusive figure here would not match the invoice.
 */
trait PreviewsGymCharge
{
    /**
     * A gym charge for a session that already exists, priced the same way the monthly
     * usage report prices it.
     *
     * @return array{name: string, charges: bool, rate: ?float, minutes: ?int, people: int, amount: ?float}|null
     *                                                                                                           null when there is no gym to say anything about
     */
    protected function gymChargeFor(?Gym $gym, int $people, ?int $minutes): ?array
    {
        if (! $gym) {
            return null;
        }

        // Mirrors the form's own bounds: a half-typed duration should blank the figure
        // rather than quote a number the trainer never meant.
        $usable = $minutes !== null && $minutes >= 5 && $minutes <= 480;
        $rate = $people >= 1 ? $gym->rateFor($people) : null;

        return [
            'name' => $gym->name,
            'charges' => $gym->chargesUsage(),
            'rate' => $rate,
            'minutes' => $usable ? $minutes : null,
            'people' => $people,
            'amount' => $rate !== null && $usable ? $gym->chargeFor($people, $minutes) : null,
        ];
    }

    /**
     * The same figure from a half-filled log form.
     *
     * Headcount is summed from the priced rows rather than taken from $preview['people'],
     * which floors at one so a rate tier always exists: on an empty form that would quote
     * the price of a session nobody is at.
     *
     * @param  array{rows: array<int, array{people: int}>}  $preview  from previewCharges()
     * @return array{name: string, charges: bool, rate: ?float, minutes: ?int, people: int, amount: ?float}|null
     */
    protected function previewGymCharge(?Gym $gym, array $preview, string $minutes): ?array
    {
        return $this->gymChargeFor(
            $gym,
            (int) array_sum(array_column($preview['rows'], 'people')),
            is_numeric($minutes) ? (int) $minutes : null,
        );
    }
}
