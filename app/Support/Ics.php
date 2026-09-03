<?php

namespace App\Support;

use App\Models\Client;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;

/**
 * Minimal iCalendar (RFC 5545) writer for session invites. Times are emitted in UTC
 * so every calendar client shows them correctly without a VTIMEZONE block.
 * A calendar may carry several VEVENTs (one per session of a repeat), each with its own UID,
 * so later single-session updates and cancellations still target exactly one event.
 */
class Ics
{
    public const METHOD_REQUEST = 'REQUEST';

    public const METHOD_CANCEL = 'CANCEL';

    public static function forSession(TrainingSession $session, Client $client, string $method = self::METHOD_REQUEST): string
    {
        return self::forSessions(collect([$session]), $client, $method);
    }

    /**
     * @param  Collection<int, TrainingSession>  $sessions
     */
    public static function forSessions(Collection $sessions, Client $client, string $method = self::METHOD_REQUEST): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//BrookeApp//Training Sessions//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
        ];

        foreach ($sessions->sortBy('starts_at') as $session) {
            $lines = array_merge($lines, self::event($session, $client, $method));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * @return list<string>
     */
    private static function event(TrainingSession $session, Client $client, string $method): array
    {
        $session->loadMissing(['service', 'trainer', 'attendees.client']);
        $trainer = $session->trainer;
        $summary = "{$session->service->name} with {$trainer->displayName()}";

        $description = collect([
            $summary,
            $session->starts_at->format('l, F j, Y \a\t g:i a').' ('.$session->duration_minutes.' min)',
            $session->attendees->count() > 1 ? 'Attending: '.$session->attendees->pluck('client.full_name')->join(', ') : null,
            '',
            'To change or cancel this session please contact '.$trainer->displayName().' directly.',
            $trainer->booking_instructions,
            $trainer->phone ? 'Phone: '.$trainer->phone : null,
            'Email: '.$trainer->email,
        ])->filter(fn ($line) => $line !== null)->join("\n");

        return [
            'BEGIN:VEVENT',
            'UID:'.$session->ensureIcsUid(),
            'SEQUENCE:'.(int) $session->ics_sequence,
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$session->starts_at->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$session->endsAt()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.self::escape($summary),
            'DESCRIPTION:'.self::escape($description),
            'ORGANIZER;CN='.self::escape($trainer->displayName()).':mailto:'.$trainer->email,
            'ATTENDEE;CN='.self::escape($client->full_name).';ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:'.$client->email,
            'STATUS:'.($method === self::METHOD_CANCEL ? 'CANCELLED' : 'CONFIRMED'),
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ];
    }

    /** Escape text per RFC 5545 §3.3.11. */
    public static function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\,', '\n', '\n'],
            $text,
        );
    }

    /** Fold lines longer than 75 octets (RFC 5545 §3.1). */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = mb_strcut($line, 0, 75);
        $rest = substr($line, strlen($out));

        while ($rest !== '') {
            $chunk = mb_strcut($rest, 0, 74);
            $out .= "\r\n ".$chunk;
            $rest = substr($rest, strlen($chunk));
        }

        return $out;
    }
}
