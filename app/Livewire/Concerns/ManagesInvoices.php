<?php

namespace App\Livewire\Concerns;

use App\Actions\DeleteInvoice;
use App\Actions\ReceiveInvoicePayment;
use App\Actions\SendInvoice;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use Flux\Flux;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;

/**
 * Remind, mark paid, void and delete — shared by the Invoices page and a client's page,
 * so the two can never behave differently.
 *
 * The page supplies findInvoice(), which decides which invoices it may touch. Pair with
 * the invoices/partials/row-actions and mark-paid-modal views.
 */
trait ManagesInvoices
{
    // Mark paid modal
    public ?int $markPaidId = null;

    public string $markPaidAmount = '';

    public string $markPaidMethod = '';

    public string $markPaidDate = '';

    public string $markPaidReference = '';

    public bool $markPaidNotify = true;

    /** The invoice with this id if this page may act on it; a 404 otherwise. */
    abstract protected function findInvoice(int $invoiceId): Invoice;

    /** Runs after any invoice changes, for pages holding state that points at one. */
    protected function invoicesChanged(): void {}

    public function remindInvoice(int $invoiceId): void
    {
        $invoice = $this->authorizedInvoice($invoiceId);

        try {
            app(SendInvoice::class)->handle($invoice, reminder: true);
        } catch (BillingException $e) {
            Flux::toast($e->getMessage(), variant: 'warning');

            return;
        }

        Flux::toast("Reminder for {$invoice->number} emailed to {$invoice->client->email}.", variant: 'success');
    }

    public function openMarkPaid(int $invoiceId): void
    {
        $invoice = $this->authorizedInvoice($invoiceId);

        if (! $invoice->isOutstanding()) {
            Flux::toast("{$invoice->number} is {$invoice->status()->label()}; there is nothing left to pay.", variant: 'warning');

            return;
        }

        $this->resetValidation(['markPaidAmount', 'markPaidMethod', 'markPaidDate', 'markPaidReference']);
        $this->markPaidId = $invoice->id;
        $this->markPaidAmount = number_format($invoice->outstandingAmount(), 2, '.', '');
        $this->markPaidMethod = auth()->user()->enabledPaymentMethods()[0]->value ?? '';
        $this->markPaidDate = today()->toDateString();
        $this->markPaidReference = '';
        $this->markPaidNotify = (bool) $invoice->client->email;

        Flux::modal('mark-paid')->show();
    }

    public function markPaid(): void
    {
        $invoice = $this->authorizedInvoice((int) $this->markPaidId);

        $this->validate([
            'markPaidAmount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'markPaidMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'markPaidDate' => ['required', 'date'],
            'markPaidReference' => ['nullable', 'string', 'max:100'],
        ]);

        $notify = $this->markPaidNotify && $invoice->client->email;

        try {
            app(ReceiveInvoicePayment::class)->handle(
                $invoice,
                (float) $this->markPaidAmount,
                PaymentMethod::from($this->markPaidMethod),
                $this->markPaidDate,
                $this->markPaidReference ?: null,
                (bool) $notify,
            );
        } catch (BillingException $e) {
            $this->addError('markPaidAmount', $e->getMessage());

            return;
        }

        $invoice = $invoice->fresh();

        $this->reset('markPaidId', 'markPaidAmount', 'markPaidReference');
        Flux::modal('mark-paid')->close();
        $this->invoicesChanged();

        $message = $invoice->status() === InvoiceStatus::Paid
            ? "{$invoice->number} is paid."
            : "Payment recorded. {$invoice->number} has ".money($invoice->outstandingAmount()).' still to pay.';

        Flux::toast($message.($notify ? ' Thank-you emailed.' : ''), variant: 'success');
    }

    public function voidInvoice(int $invoiceId): void
    {
        $invoice = $this->authorizedInvoice($invoiceId);

        if (! $invoice->isOutstanding()) {
            Flux::toast("{$invoice->number} is {$invoice->status()->label()}; there is nothing left to cancel.", variant: 'warning');

            return;
        }

        // Only the request is cancelled. Any money that already arrived against it
        // stays in the ledger, because it really did arrive.
        $invoice->forceFill(['voided_at' => now()])->save();
        $this->invoicesChanged();

        Flux::toast("{$invoice->number} voided.", variant: 'success');
    }

    public function deleteInvoice(int $invoiceId): void
    {
        $invoice = $this->authorizedInvoice($invoiceId);

        try {
            app(DeleteInvoice::class)->handle($invoice);
        } catch (BillingException $e) {
            Flux::toast($e->getMessage(), variant: 'warning');

            return;
        }

        $this->invoicesChanged();

        Flux::toast("{$invoice->number} deleted.", variant: 'success');
    }

    /**
     * The invoice the mark-paid modal is about, for the view. Forgiving, because the id
     * outlives a cancelled modal: delete that invoice afterwards and it is simply gone.
     */
    protected function markPaidInvoice(): ?Invoice
    {
        if (! $this->markPaidId) {
            return null;
        }

        try {
            return $this->findInvoice($this->markPaidId)->loadMissing('client');
        } catch (ModelNotFoundException) {
            $this->markPaidId = null;

            return null;
        }
    }

    private function authorizedInvoice(int $invoiceId): Invoice
    {
        $invoice = $this->findInvoice($invoiceId)->loadMissing('client');

        $this->authorize('update', $invoice->client);

        return $invoice;
    }
}
