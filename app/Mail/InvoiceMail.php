<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\WalletOutlook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks the client for money: what is owed, how to pay it, and a link to their wallet.
 */
class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $trainer = $this->invoice->client->trainer;

        $subject = $this->invoice->client->isOnMonthlyPlan()
            ? "Your {$this->invoice->issued_on->format('F')} membership fee — {$trainer->displayName()}"
            : "Time to top up your Fitness Wallet — {$trainer->displayName()}";

        return new Envelope(
            from: new Address(config('mail.from.address'), $trainer->displayName()),
            replyTo: [new Address($trainer->email, $trainer->displayName())],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $client = $this->invoice->client;
        $balance = $client->balance();

        return new Content(
            markdown: 'mail.invoice',
            with: [
                'invoice' => $this->invoice,
                'client' => $client,
                'trainer' => $client->trainer,
                'balance' => $balance,
                'sessionsLeft' => WalletOutlook::sessionsRemaining($client, $balance),
                'url' => $client->portalUrl(),
            ],
        );
    }
}
