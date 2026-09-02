<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Models\Client;
use App\Models\WalletTransaction;
use App\Services\GstCalculator;
use Carbon\CarbonInterface;

/**
 * Manual ledger corrections.
 *  - Adjustment: signed credit (+) or debit (−) with a required note; no GST.
 *  - Refund: money returned to the client (negative amount); embedded GST is recorded
 *    so it nets against payments received in reports.
 */
class PostAdjustment
{
    public function handle(
        Client $client,
        float $signedAmount,
        string $note,
        TransactionType $type = TransactionType::Adjustment,
        CarbonInterface|string|null $on = null,
        ?PaymentMethod $method = null,
    ): WalletTransaction {
        if (! in_array($type, [TransactionType::Adjustment, TransactionType::Refund], true)) {
            throw new BillingException('Only adjustments and refunds can be posted manually.');
        }

        if ($signedAmount == 0.0) {
            throw new BillingException('Amount cannot be zero.');
        }

        if (trim($note) === '') {
            throw new BillingException('A note explaining the adjustment is required.');
        }

        $signedAmount = round($signedAmount, 2);

        if ($type === TransactionType::Refund) {
            $signedAmount = -abs($signedAmount);
            $client->loadMissing('trainer');
            $gst = GstCalculator::embeddedIn(abs($signedAmount), $client->trainer->effectiveGstRate());
        } else {
            $gst = 0.0;
        }

        return WalletTransaction::create([
            'user_id' => $client->user_id,
            'client_id' => $client->id,
            'type' => $type,
            'amount' => $signedAmount,
            'subtotal' => round(abs($signedAmount) - $gst, 2),
            'gst_amount' => $gst,
            'payment_method' => $type === TransactionType::Refund ? $method : null,
            'transacted_on' => $on ? (is_string($on) ? $on : $on->toDateString()) : today()->toDateString(),
            'description' => trim($note),
        ]);
    }
}
