<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\WalletTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Thanks the client for a payment against an invoice. Carries the payment row itself, so
 * "we've received $X" is what actually arrived rather than a figure worked out later.
 */
class PaymentReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public WalletTransaction $payment) {}

    public function envelope(): Envelope
    {
        $trainer = $this->invoice->client->trainer;

        return new Envelope(
            from: new Address(config('mail.from.address'), $trainer->displayName()),
            replyTo: [new Address($trainer->email, $trainer->displayName())],
            subject: "Payment received for {$this->invoice->number} — {$trainer->displayName()}",
        );
    }

    public function content(): Content
    {
        $client = $this->invoice->client;
        $outstanding = $this->invoice->outstandingAmount();

        return new Content(
            markdown: 'mail.payment-received',
            with: [
                'invoice' => $this->invoice,
                'payment' => $this->payment,
                'client' => $client,
                'trainer' => $client->trainer,
                'paid' => $this->invoice->paidAmount(),
                'outstanding' => $outstanding,
                'paidInFull' => $outstanding <= 0,
                'balance' => $client->balance(),
                'url' => $client->portalUrl(),
            ],
        );
    }
}
