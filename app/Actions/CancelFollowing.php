<?php

namespace App\Actions;

use App\Enums\SessionStatus;
use App\Mail\SeriesMail;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\DB;

/**
 * "Cancel this and following": cancels the anchor and every later scheduled session of its
 * series, ends the series the day before the anchor, and emails one cancellation per invited client.
 */
class CancelFollowing
{
    public function __construct(private SendSeriesInvites $invites) {}

    /** @return int number of sessions cancelled */
    public function handle(TrainingSession $anchor): int
    {
        $series = $anchor->series;

        if (! $series) {
            return 0;
        }

        $following = $series->scheduledFrom($anchor);

        DB::transaction(function () use ($following, $series, $anchor) {
            foreach ($following as $session) {
                $session->update(['status' => SessionStatus::Cancelled, 'ics_sequence' => $session->ics_sequence + 1]);
            }

            $newEnd = $anchor->starts_at->copy()->subDay()->startOfDay();
            $series->update(['ends_on' => $newEnd->lt($series->starts_on) ? $series->starts_on : $newEnd]);
        });

        $invited = $following->filter(fn (TrainingSession $s) => $s->invitesWereSent());

        if ($invited->isNotEmpty()) {
            $this->invites->handle($invited->map->fresh(['service', 'gym', 'trainer', 'series', 'attendees.client']), SeriesMail::CANCELLED);
        }

        return $following->count();
    }
}
