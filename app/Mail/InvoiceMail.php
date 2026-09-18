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
 * As a reminder, the same email leads with the invoice still being open and shows what
 * is left to pay after anything already received.
 */
class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public bool $reminder = false) {}

    public function envelope(): Envelope
    {
        $trainer = $this->invoice->client->trainer;

        $subject = match (true) {
            $this->reminder => "Reminder: {$this->invoice->number} {$this->dueWording()} — {$trainer->displayName()}",
            $this->invoice->client->isOnMonthlyPlan() => "Your {$this->invoice->issued_on->format('F')} membership fee — {$trainer->displayName()}",
            default => "Time to top up your Fitness Wallet — {$trainer->displayName()}",
        };

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
                'reminder' => $this->reminder,
                'dueWording' => $this->dueWording(bold: true),
                'paid' => $this->invoice->paidAmount(),
                'outstanding' => $this->invoice->outstandingAmount(),
            ],
        );
    }

    /** "is due Sep 24", "is overdue" or "is still open", for the subject and the opening line. */
    private function dueWording(bool $bold = false): string
    {
        $due = $this->invoice->due_on;

        if (! $due) {
            return 'is still open';
        }

        $date = $bold ? '**'.$due->format('M j, Y').'**' : $due->format('M j');

        return $due->isBefore(today()) ? "is overdue (it was due {$date})" : "is due {$date}";
    }
}
