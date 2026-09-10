<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Models\Plan;
use App\Models\Service;
use App\Support\AttendeeLine;

/**
 * Works out what a session costs, all of it at once, because the rate tier depends on
 * how many people are in the room and nobody's price can be settled alone.
 *
 * This is the only place session money is calculated: the preview a trainer sees while
 * filling in the form and the charge that lands in the ledger both come from here, so
 * the number on the button is the number on the receipt.
 */
class SessionPricer
{
    public function __construct(private PriceResolver $prices) {}

    /**
     * @param  array<int, AttendeeLine>  $lines  keyed by client id
     * @return array{
     *     people: int,
     *     tier: string,
     *     rateTier: string,
     *     total: float,
     *     rows: array<int, array{
     *         line: AttendeeLine, attended: bool, people: int,
     *         subtotal: ?float, gst: ?float, total: ?float,
     *         members: array<int, array{id: int, name: string, subtotal: float}>,
     *         error: ?string,
     *     }>,
     * }
     */
    public function price(array $lines, ?Service $service, float $gstRate): array
    {
        $people = max(1, array_sum(array_map(fn (AttendeeLine $line) => $line->people, $lines)));
        $rows = [];
        $total = 0.0;

        foreach ($lines as $clientId => $line) {
            $row = [
                'line' => $line,
                'attended' => $line->attended(),
                'people' => $line->people,
                'subtotal' => null,
                'gst' => null,
                'total' => null,
                'members' => [],
                'error' => null,
            ];

            if ($line->attended() && $service) {
                try {
                    [$subtotal, $members] = $this->subtotalFor($line, $service, $people);
                    $gst = GstCalculator::onExclusive($subtotal, $gstRate);

                    $row['subtotal'] = $subtotal;
                    $row['gst'] = $gst;
                    $row['total'] = round($subtotal + $gst, 2);
                    $row['members'] = $members;
                    $total += $row['total'];
                } catch (BillingException $e) {
                    $row['error'] = $e->getMessage();
                }
            }

            $rows[(int) $clientId] = $row;
        }

        return [
            'people' => $people,
            'tier' => Plan::headcountLabel($people),
            'rateTier' => Plan::rateTierLabel($people),
            'total' => round($total, 2),
            'rows' => $rows,
        ];
    }

    /**
     * A family pays for each member who turned up; everyone else pays for themselves.
     * GST is deliberately left to the caller and worked out once on this subtotal —
     * per-person GST does not always add up to GST on the sum, and there is only one
     * wallet charge for the breakdown to reconcile against.
     *
     * @return array{0: float, 1: array<int, array{id: int, name: string, subtotal: float}>}
     */
    private function subtotalFor(AttendeeLine $line, Service $service, int $people): array
    {
        if (! $line->isFamily()) {
            return [$this->prices->forAttendee($line->client, $service, $people, $line->override), []];
        }

        $members = [];
        $subtotal = 0.0;

        foreach ($line->attendingMembers() as $member) {
            $share = $this->prices->forAttendee($line->client, $service, $people, $member->override ?? $line->override);
            $members[] = ['id' => $member->id, 'name' => $member->name, 'subtotal' => $share];
            $subtotal += $share;
        }

        return [round($subtotal, 2), $members];
    }
}
