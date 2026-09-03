<?php

namespace App\Mail;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the client their private Fitness Wallet link.
 */
class WalletLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Client $client) {}

    public function envelope(): Envelope
    {
        $trainer = $this->client->trainer;

        return new Envelope(
            from: new Address(config('mail.from.address'), $trainer->displayName()),
            replyTo: [new Address($trainer->email, $trainer->displayName())],
            subject: "Your Fitness Wallet with {$trainer->displayName()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.wallet-link',
            with: [
                'client' => $this->client,
                'trainer' => $this->client->trainer,
                'url' => $this->client->portalUrl(),
                'balance' => $this->client->balance(),
            ],
        );
    }
}
