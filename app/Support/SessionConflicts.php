<?php

namespace App\Support;

use App\Enums\SessionStatus;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Finds sessions that overlap in time. A trainer can only be in one place at once,
 * so an overlap is worth flagging — but it is a warning, never a block: back-to-back
 * bookings, a hand-off to another trainer and plain data entry all look like clashes.
 *
 * Cancelled sessions never clash, and touching sessions (one ends exactly as the
 * next begins) do not either.
 */
class SessionConflicts
{
    /** The longest session the forms allow, so a clash can start at most this far back. */
    public const MAX_DURATION_MINUTES = 480;

    /**
     * Sessions already in the diary that overlap this slot, soonest first.
     *
     * @return EloquentCollection<int, TrainingSession>
     */
    public static function at(CarbonInterface|string $startsAt, int $durationMinutes, ?int $ignoreId = null): EloquentCollection
    {
        $start = CarbonImmutable::parse($startsAt);
        $end = $start->addMinutes(max(1, $durationMinutes));

        $candidates = TrainingSession::query()
            ->with(['service', 'attendees.client'])
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereBetween('starts_at', [$start->subMinutes(self::MAX_DURATION_MINUTES), $end])
            ->orderBy('starts_at')
            ->get();

        return $candidates
            ->filter(fn (TrainingSession $session) => self::overlaps($session->starts_at, $session->duration_minutes, $start, $end))
            ->values();
    }

    /**
     * Which of these slots already clash with something, without a query per slot.
     * Used for repeats and bulk batches, where the same time repeats across many dates.
     *
     * @param  list<CarbonInterface|string>  $starts
     * @return array<string, EloquentCollection<int, TrainingSession>> keyed by "Y-m-d H:i"
     */
    public static function forSlots(array $starts, int $durationMinutes): array
    {
        if ($starts === []) {
            return [];
        }

        $slots = collect($starts)->map(fn ($start) => CarbonImmutable::parse($start))->sort()->values();
        $duration = max(1, $durationMinutes);

        $candidates = TrainingSession::query()
            ->with(['service', 'attendees.client'])
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereBetween('starts_at', [
                $slots->first()->subMinutes(self::MAX_DURATION_MINUTES),
                $slots->last()->addMinutes($duration),
            ])
            ->orderBy('starts_at')
            ->get();

        $clashes = [];

        foreach ($slots as $slot) {
            $end = $slot->addMinutes($duration);
            $overlapping = $candidates
                ->filter(fn (TrainingSession $session) => self::overlaps($session->starts_at, $session->duration_minutes, $slot, $end))
                ->values();

            if ($overlapping->isNotEmpty()) {
                $clashes[$slot->format('Y-m-d H:i')] = $overlapping;
            }
        }

        return $clashes;
    }

    /**
     * Ids of sessions that overlap another session in the same set — the calendar
     * already has its whole window loaded, so this needs no further queries.
     *
     * @param  Collection<int, TrainingSession>  $sessions
     * @return array<int, true>
     */
    public static function within(Collection $sessions): array
    {
        $active = $sessions
            ->reject(fn (TrainingSession $session) => $session->isCancelled())
            ->sortBy('starts_at')
            ->values();

        $conflicting = [];

        foreach ($active as $i => $session) {
            $end = $session->endsAt();

            // Sorted by start, so stop as soon as a later session begins after this one ends.
            for ($j = $i + 1; $j < $active->count(); $j++) {
                $other = $active[$j];

                if ($other->starts_at->gte($end)) {
                    break;
                }

                $conflicting[$session->id] = true;
                $conflicting[$other->id] = true;
            }
        }

        return $conflicting;
    }

    private static function overlaps(CarbonInterface $start, int $durationMinutes, CarbonInterface $otherStart, CarbonInterface $otherEnd): bool
    {
        return $start->lt($otherEnd) && $start->copy()->addMinutes(max(1, $durationMinutes))->gt($otherStart);
    }
}
