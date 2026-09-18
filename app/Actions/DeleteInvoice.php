<?php

namespace App\Actions;

use App\Exceptions\BillingException;
use App\Models\Invoice;

/**
 * Removes an invoice raised by mistake. Only while nothing has been received against it:
 * after that the ledger names it, so it can be voided but not made to disappear.
 *
 * The delete is soft, so the number stays taken — it may already be in the client's inbox.
 */
class DeleteInvoice
{
    public function handle(Invoice $invoice): void
    {
        if (! $invoice->canBeDeleted()) {
            throw new BillingException("{$invoice->number} has money received against it. Void it instead.");
        }

        $invoice->delete();
    }
}
