<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\TrainingSession;
use App\Support\Ics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * One email covering several sessions of a repeating booking: booked, updated or cancelled.
 * The attached .ics carries one event per session so calendars stay in sync per occurrence.
 */
class SeriesMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const BOOKED = 'booked';

    public const UPDATED = 'updated';

    public const CANCELLED = 'cancelled';

    /**
     * @param  Collection<int, TrainingSession>  $sessions
     */
    public function __construct(
        public Collection $sessions,
        public Client $client,
        public string $kind = self::BOOKED,
    ) {}

    public function envelope(): Envelope
    {
        $trainer = $this->sessions->first()->trainer;
        $count = $this->sessions->count();
        $noun = $count.' training '.str('session')->plural($count);

        return new Envelope(
            from: new Address(config('mail.from.address'), $trainer->displayName()),
            replyTo: [new Address($trainer->email, $trainer->displayName())],
            subject: match ($this->kind) {
                self::UPDATED => "Updated: {$noun} with {$trainer->displayName()}",
                self::CANCELLED => "Cancelled: {$noun} with {$trainer->displayName()}",
                default => "{$noun} booked with {$trainer->displayName()}",
            },
        );
    }

    public function content(): Content
    {
        $sessions = $this->sessions->sortBy('starts_at')->values();
        $first = $sessions->first();

        return new Content(
            markdown: 'mail.sessions.series',
            with: [
                'sessions' => $sessions,
                'client' => $this->client,
                'trainer' => $first->trainer,
                'kind' => $this->kind,
                'series' => $first->series,
            ],
        );
    }

    public function attachments(): array
    {
        $method = $this->kind === self::CANCELLED ? Ics::METHOD_CANCEL : Ics::METHOD_REQUEST;
        $ics = Ics::forSessions($this->sessions, $this->client, $method);
        $name = $this->kind === self::CANCELLED ? 'cancel.ics' : 'invite.ics';

        return [
            Attachment::fromData(fn () => $ics, $name)->withMime("text/calendar; charset=utf-8; method={$method}"),
        ];
    }
}
