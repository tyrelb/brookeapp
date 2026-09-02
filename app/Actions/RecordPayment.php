<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Exceptions\PaymentMethodNotAcceptedException;
use App\Models\Client;
use App\Models\WalletTransaction;
use App\Services\GstCalculator;
use Carbon\CarbonInterface;

/**
 * Money received from a client (a wallet deposit or a monthly-fee payment).
 * The amount is treated as GST-inclusive; the embedded GST is recorded for reporting.
 */
class RecordPayment
{
    public function handle(
        Client $client,
        float $amount,
        PaymentMethod $method,
        CarbonInterface|string|null $receivedOn = null,
        ?string $reference = null,
        ?string $description = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new BillingException('Payment amount must be greater than zero.');
        }

        $client->loadMissing('trainer');

        if (! $client->trainer->acceptsPaymentMethod($method)) {
            throw new PaymentMethodNotAcceptedException("{$method->label()} is not an accepted payment method. Enable it under Settings → Business.");
        }

        $amount = round($amount, 2);
        $gst = GstCalculator::embeddedIn($amount, $client->trainer->effectiveGstRate());

        return WalletTransaction::create([
            'user_id' => $client->user_id,
            'client_id' => $client->id,
            'type' => TransactionType::Payment,
            'amount' => $amount,
            'subtotal' => round($amount - $gst, 2),
            'gst_amount' => $gst,
            'payment_method' => $method,
            'reference' => $reference ?: null,
            'transacted_on' => $receivedOn ? (is_string($receivedOn) ? $receivedOn : $receivedOn->toDateString()) : today()->toDateString(),
            'description' => $description ?: ($client->isOnWalletPlan() ? 'Fitness Wallet deposit' : 'Payment'),
        ]);
    }
}
