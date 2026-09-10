<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Exceptions\PaymentMethodNotAcceptedException;
use App\Models\WalletTransaction;
use App\Models\WalletTransactionRevision;
use App\Services\GstCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a ledger row that was entered wrong (wrong amount, date, method,
 * reference or wording). GST is recalculated the same way the row was created,
 * and the change is written to the row's revision history with a reason.
 *
 * Session charges are not editable here — they follow their training session.
 */
class UpdateTransaction
{
    public function handle(
        WalletTransaction $transaction,
        float $signedAmount,
        CarbonInterface|string $on,
        string $reason,
        ?string $description = null,
        ?PaymentMethod $method = null,
        ?string $reference = null,
    ): WalletTransaction {
        if ($transaction->type === TransactionType::SessionCharge) {
            throw new BillingException('Session charges are corrected by reopening the session.');
        }

        if ($transaction->isVoided()) {
            throw new BillingException('A voided transaction cannot be edited.');
        }

        if (trim($reason) === '') {
            throw new BillingException('A reason for the change is required.');
        }

        if ($signedAmount == 0.0) {
            throw new BillingException('Amount cannot be zero.');
        }

        $transaction->loadMissing('trainer');
        $rate = $transaction->trainer->effectiveGstRate();
        $magnitude = round(abs($signedAmount), 2);
        $description = $description !== null ? trim($description) : null;

        if (in_array($transaction->type, [TransactionType::Adjustment, TransactionType::Refund], true) && ($description === null || $description === '')) {
            throw new BillingException('A note explaining this entry is required.');
        }

        if (in_array($transaction->type, [TransactionType::Payment, TransactionType::Refund], true) && $method !== null
            && ! $transaction->trainer->acceptsPaymentMethod($method)) {
            throw new PaymentMethodNotAcceptedException("{$method->label()} is not an accepted payment method. Enable it under Settings → Business.");
        }

        if ($transaction->type === TransactionType::Payment && $method === null) {
            throw new BillingException('A payment method is required.');
        }

        [$amount, $subtotal, $gst] = match ($transaction->type) {
            // Received or returned money: the amount entered includes GST.
            TransactionType::Payment, TransactionType::Refund => (function () use ($transaction, $magnitude, $rate) {
                $gst = GstCalculator::embeddedIn($magnitude, $rate);
                $signed = $transaction->type === TransactionType::Refund ? -$magnitude : $magnitude;

                return [$signed, round($magnitude - $gst, 2), $gst];
            })(),
            // A membership fee is entered before GST, which is added on top.
            TransactionType::MonthlyFee => (function () use ($magnitude, $rate) {
                $gst = GstCalculator::onExclusive($magnitude, $rate);

                return [-round($magnitude + $gst, 2), $magnitude, $gst];
            })(),
            // Adjustments carry no GST and keep the sign the caller asked for.
            default => [round($signedAmount, 2), $magnitude, 0.0],
        };

        $after = [
            'transacted_on' => $on instanceof CarbonInterface ? $on->toDateString() : CarbonImmutable::parse($on)->toDateString(),
            'description' => $description ?: null,
            'payment_method' => $transaction->type->isMoneyMovement() ? $method?->value : null,
            'reference' => $transaction->type->isMoneyMovement() && $reference !== null && trim($reference) !== '' ? trim($reference) : null,
            'subtotal' => $subtotal,
            'gst_amount' => $gst,
            'amount' => $amount,
        ];

        $changes = $this->diff($transaction, $after);

        if ($changes === []) {
            throw new BillingException('Nothing was changed.');
        }

        return DB::transaction(function () use ($transaction, $after, $changes, $reason) {
            $transaction->forceFill($after)->save();
            $transaction->recordRevision(WalletTransactionRevision::ACTION_UPDATED, $changes, $reason);

            return $transaction->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(WalletTransaction $transaction, array $after): array
    {
        $changes = [];

        foreach (array_keys(WalletTransactionRevision::TRACKED) as $field) {
            $from = $this->normalize($field, $transaction->getRawOriginal($field));
            $to = $this->normalize($field, $after[$field]);

            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    private function normalize(string $field, mixed $value): string|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'amount', 'subtotal', 'gst_amount' => round((float) $value, 2),
            'transacted_on' => substr((string) $value, 0, 10),
            default => (string) $value,
        };
    }
}
