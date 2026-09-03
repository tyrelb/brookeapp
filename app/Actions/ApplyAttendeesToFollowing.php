<?php

namespace App\Actions;

use App\Models\TrainingSession;
use Illuminate\Support\Facades\DB;

/**
 * Copies the anchor session's attendee list onto every later scheduled session of its series.
 * Clients newly added to sessions that already had invites out get a booking email for those dates.
 *
 * @return int number of following sessions updated (anchor excluded)
 */
class ApplyAttendeesToFollowing
{
    public function __construct(private SendSeriesInvites $invites) {}

    public function handle(TrainingSession $anchor): int
    {
        $series = $anchor->series;

        if (! $series) {
            return 0;
        }

        $template = $anchor->attendees()->get()->keyBy('client_id');
        $following = $series->scheduledFrom($anchor)->reject(fn (TrainingSession $s) => $s->is($anchor));
        $newlyInvited = []; // client id => sessions

        DB::transaction(function () use ($following, $template, &$newlyInvited) {
            foreach ($following as $session) {
                $existing = $session->attendees->keyBy('client_id');

                $session->attendees()->whereNotIn('client_id', $template->keys())->delete();

                foreach ($template as $clientId => $attendee) {
                    if ($existing->has($clientId)) {
                        $existing[$clientId]->update(['price_override' => $attendee->price_override]);

                        continue;
                    }

                    $session->attendees()->create(['client_id' => $clientId, 'attended' => true, 'price_override' => $attendee->price_override]);

                    if ($session->invitesWereSent()) {
                        $newlyInvited[$clientId][] = $session->id;
                    }
                }
            }
        });

        foreach ($newlyInvited as $clientId => $sessionIds) {
            $sessions = TrainingSession::query()->forTrainer($anchor->user_id)->whereIn('id', $sessionIds)->with(['service', 'gym', 'trainer', 'series', 'attendees.client'])->get();
            $this->invites->handle($sessions, onlyClientIds: [$clientId]);
        }

        return $following->count();
    }
}
