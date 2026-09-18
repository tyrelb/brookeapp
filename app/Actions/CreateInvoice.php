<?php

namespace App\Actions;

use App\Exceptions\BillingException;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Scopes\TrainerScope;
use App\Services\PaymentRequestSuggester;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Raises a numbered request for money against a client. The line items and the GST
 * rate are frozen onto the row, so renaming a plan or changing the GST rate later
 * never rewrites what the client was already asked to pay.
 */
class CreateInvoice
{
    public function __construct(private PaymentRequestSuggester $suggester) {}

    public function handle(
        Client $client,
        float $subtotal,
        CarbonInterface|string|null $issuedOn = null,
        CarbonInterface|string|null $dueOn = null,
        ?string $message = null,
    ): Invoice {
        if ($subtotal <= 0) {
            throw new BillingException('The amount must be greater than zero.');
        }

        $client->loadMissing('trainer', 'plan.rates');

        $totals = $this->suggester->totals(
            $client,
            $this->suggester->linesFor($client, round($subtotal, 2)),
        );

        $attributes = [
            'user_id' => $client->user_id,
            'client_id' => $client->id,
            'lines' => $totals['lines'],
            'subtotal' => $totals['subtotal'],
            'gst_amount' => $totals['gst'],
            'gst_rate' => $totals['gst_rate'],
            'total' => $totals['total'],
            'issued_on' => $this->date($issuedOn) ?? today()->toDateString(),
            'due_on' => $this->date($dueOn),
            'message' => $message ?: null,
        ];

        // The unique index on (user_id, sequence) is the only real guarantee here, so
        // take the number optimistically and retry if someone else got it first. A
        // lock would not help: the very first invoice for a trainer has no row to
        // lock, and lockForUpdate() compiles to nothing on SQLite, so the retry would
        // still have to exist. One mechanism, working the same everywhere.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return Invoice::create($attributes + $this->nextNumber($client));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new BillingException('Could not allocate an invoice number. Please try again.');
    }

    /**
     * @return array{sequence: int, number: string}
     */
    private function nextNumber(Client $client): array
    {
        // withTrashed: a deleted invoice's number may already be in a client's inbox,
        // so it is never handed out again.
        $sequence = 1 + (int) Invoice::query()
            ->withoutGlobalScope(TrainerScope::class)
            ->withTrashed()
            ->where('user_id', $client->user_id)
            ->max('sequence');

        return [
            'sequence' => $sequence,
            'number' => 'INV-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        ];
    }

    private function date(CarbonInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : $value->toDateString();
    }
}
