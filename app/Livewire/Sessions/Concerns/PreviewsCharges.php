<?php

namespace App\Livewire\Sessions\Concerns;

use App\Exceptions\BillingException;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Service;
use App\Services\GstCalculator;
use App\Services\PriceResolver;
use Illuminate\Support\Collection;

/**
 * Shared "what will each attendee be charged?" preview used by the log and show screens.
 *
 * @property array<int, array{attended: bool, override: string}> $attendees keyed by client id
 */
trait PreviewsCharges
{
    /**
     * @param  Collection<int, Client>  $clients  keyed by id, with plan.rates loaded
     * @return array{headcount: int, tier: string, rows: array<int, array{client: Client, attended: bool, subtotal: ?float, gst: ?float, total: ?float, error: ?string}>, total: float}
     */
    protected function previewCharges(?Service $service, Collection $clients): array
    {
        $resolver = app(PriceResolver::class);
        $gstRate = auth()->user()->effectiveGstRate();
        $headcount = max(1, collect($this->attendees)->where('attended', true)->count());
        $rows = [];
        $total = 0.0;

        foreach ($this->attendees as $clientId => $state) {
            $client = $clients->get((int) $clientId);

            if (! $client) {
                continue;
            }

            $row = ['client' => $client, 'attended' => (bool) $state['attended'], 'subtotal' => null, 'gst' => null, 'total' => null, 'error' => null];

            if ($row['attended'] && $service) {
                try {
                    $override = ($state['override'] ?? '') !== '' && is_numeric($state['override']) ? (float) $state['override'] : null;
                    $subtotal = $resolver->forAttendee($client, $service, $headcount, $override);
                    $gst = GstCalculator::onExclusive($subtotal, $gstRate);
                    $row['subtotal'] = $subtotal;
                    $row['gst'] = $gst;
                    $row['total'] = round($subtotal + $gst, 2);
                    $total += $row['total'];
                } catch (BillingException $e) {
                    $row['error'] = $e->getMessage();
                }
            }

            $rows[(int) $clientId] = $row;
        }

        return [
            'headcount' => $headcount,
            'tier' => Plan::headcountLabel($headcount),
            'rows' => $rows,
            'total' => round($total, 2),
        ];
    }
}
