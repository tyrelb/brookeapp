<?php

namespace App\Actions;

use App\Exceptions\BillingException;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a payment request to the client, or a reminder about one still open.
 *
 * A reminder carries everything the original did — the amount, how to pay, the wallet
 * link — so it doubles as "I lost the email". It stamps reminded_at rather than sent_at,
 * so the trainer can see when she last chased it.
 */
class SendInvoice
{
    public function handle(Invoice $invoice, bool $reminder = false): Invoice
    {
        $invoice->loadMissing('client.trainer', 'client.plan.rates');

        $client = $invoice->client;

        if ($invoice->isVoided()) {
            throw new BillingException("{$invoice->number} was voided and cannot be sent.");
        }

        if ($reminder && ! $invoice->canBeReminded()) {
            throw new BillingException("{$invoice->number} is already paid.");
        }

        if (! $client->email) {
            throw new BillingException("Add an email address for {$client->first_name} first.");
        }

        Mail::to($client->email, $client->full_name)->queue(new InvoiceMail($invoice, $reminder));

        $invoice->forceFill($reminder
            ? ['reminded_at' => now(), 'reminder_count' => $invoice->reminder_count + 1]
            : ['sent_at' => now()],
        )->save();

        return $invoice;
    }
}
