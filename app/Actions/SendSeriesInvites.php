<?php

namespace App\Actions;

use App\Mail\SeriesMail;
use App\Models\Client;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * One email per client covering every given session they attend.
 *
 * - booked: everyone with an email address; marks invites as sent.
 * - updated / cancelled: only clients who were sent an invite for that session.
 *
 * @return int number of emails queued
 */
class SendSeriesInvites
{
    /**
     * @param  Collection<int, TrainingSession>  $sessions
     * @param  list<int>|null  $onlyClientIds  restrict to these clients (e.g. people newly added)
     */
    public function handle(Collection $sessions, string $kind = SeriesMail::BOOKED, ?array $onlyClientIds = null): int
    {
        $sessions->each(fn (TrainingSession $s) => $s->loadMissing(['service', 'gym', 'trainer', 'series', 'attendees.client']));

        /** @var array<int, array{client: Client, sessions: array<int, TrainingSession>}> $perClient */
        $perClient = [];

        foreach ($sessions as $session) {
            $session->ensureIcsUid();

            foreach ($session->attendees as $attendee) {
                $client = $attendee->client;

                if (! $client?->email) {
                    continue;
                }

                if ($onlyClientIds !== null && ! in_array($client->id, $onlyClientIds, true)) {
                    continue;
                }

                if ($kind !== SeriesMail::BOOKED && $attendee->invite_sent_at === null) {
                    continue;
                }

                $perClient[$client->id] ??= ['client' => $client, 'sessions' => []];
                $perClient[$client->id]['sessions'][$session->id] = $session;

                if ($kind !== SeriesMail::CANCELLED) {
                    $attendee->forceFill(['invite_sent_at' => now()])->save();
                }
            }
        }

        foreach ($perClient as $entry) {
            Mail::to($entry['client']->email, $entry['client']->full_name)
                ->queue(new SeriesMail(collect(array_values($entry['sessions'])), $entry['client'], $kind));
        }

        if ($kind !== SeriesMail::CANCELLED && $perClient !== []) {
            $sessions->each(fn (TrainingSession $s) => $s->attendees->contains(fn ($a) => $a->invite_sent_at !== null)
                ? $s->forceFill(['invites_sent_at' => now()])->save()
                : null);
        }

        return count($perClient);
    }
}
