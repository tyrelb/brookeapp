<?php

namespace App\Actions;

use App\Exceptions\BillingException;
use App\Mail\InvoiceMail;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a payment request to the client and stamps when it went. Re-sending is
 * deliberately allowed — clients lose emails — and only moves the timestamp.
 */
class SendInvoice
{
    public function handle(Invoice $invoice): Invoice
    {
        $invoice->loadMissing('client.trainer', 'client.plan.rates');

        $client = $invoice->client;

        if ($invoice->isVoided()) {
            throw new BillingException('This request was voided and cannot be sent.');
        }

        if (! $client->email) {
            throw new BillingException('Add an email address for this client first.');
        }

        Mail::to($client->email, $client->full_name)->queue(new InvoiceMail($invoice));

        $invoice->forceFill(['sent_at' => now()])->save();

        return $invoice;
    }
}
