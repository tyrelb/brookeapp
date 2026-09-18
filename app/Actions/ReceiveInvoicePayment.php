<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Exceptions\BillingException;
use App\Mail\PaymentReceivedMail;
use App\Models\Invoice;
use App\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;

/**
 * "Mark paid": records the money that arrived against an invoice, and optionally thanks
 * the client for it.
 *
 * It is a real payment on the ledger, not a flag on the invoice. Paid is worked out from
 * the payments linked to an invoice, so this is the only way one can become paid — and
 * voiding this payment later puts the invoice back to owing, as it should.
 */
class ReceiveInvoicePayment
{
    public function __construct(private RecordPayment $recordPayment) {}

    public function handle(
        Invoice $invoice,
        float $amount,
        PaymentMethod $method,
        CarbonInterface|string|null $receivedOn = null,
        ?string $reference = null,
        bool $notify = true,
    ): WalletTransaction {
        if ($invoice->isVoided()) {
            throw new BillingException("{$invoice->number} was voided. Record the payment on the client's page instead.");
        }

        $invoice->loadMissing('client.trainer');
        $client = $invoice->client;

        $payment = $this->recordPayment->handle($client, $amount, $method, $receivedOn, $reference, null, $invoice);

        if ($notify && $client->email) {
            Mail::to($client->email, $client->full_name)->queue(new PaymentReceivedMail($invoice, $payment));
        }

        return $payment;
    }
}
