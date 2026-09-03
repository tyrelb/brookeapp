<?php

namespace App\Mail;

use App\Models\Plan;
use App\Models\SessionAttendee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Receipt after a completed session: what was charged and the client's remaining balance.
 */
class SessionCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SessionAttendee $attendee) {}

    public function envelope(): Envelope
    {
        $trainer = $this->attendee->trainingSession->trainer;

        return new Envelope(
            from: new Address(config('mail.from.address'), $trainer->displayName()),
            replyTo: [new Address($trainer->email, $trainer->displayName())],
            subject: "Thanks for training today — {$this->attendee->trainingSession->service->name}",
        );
    }

    public function content(): Content
    {
        $session = $this->attendee->trainingSession;
        $client = $this->attendee->client;

        return new Content(
            markdown: 'mail.sessions.completed',
            with: [
                'attendee' => $this->attendee,
                'session' => $session,
                'client' => $client,
                'trainer' => $session->trainer,
                'balance' => $client->balance(),
                'tier' => Plan::headcountLabel($session->headcount()),
                'isMonthly' => $client->isOnMonthlyPlan(),
            ],
        );
    }
}
