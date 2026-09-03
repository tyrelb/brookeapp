<?php

namespace App\Support;

use App\Exceptions\RecurrenceException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Expands a weekly repeat pattern into concrete dates. Pure and side-effect free.
 */
class Recurrence
{
    public const MAX_MONTHS = 12;

    public const MAX_OCCURRENCES = 100;

    public const INTERVALS = [1 => 'Every week', 2 => 'Every 2 weeks', 4 => 'Every 4 weeks'];

    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * @param  list<int>  $weekdays  ISO weekday numbers (1 = Monday … 7 = Sunday)
     * @return list<CarbonImmutable> dates, oldest first, ends_on inclusive
     *
     * @throws RecurrenceException
     */
    public static function occurrences(CarbonInterface|string $startsOn, CarbonInterface|string $endsOn, array $weekdays, int $intervalWeeks = 1): array
    {
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));

        if ($weekdays === [] || array_diff($weekdays, range(1, 7)) !== []) {
            throw new RecurrenceException('Choose at least one day of the week to repeat on.');
        }

        if (! in_array($intervalWeeks, array_keys(self::INTERVALS), true)) {
            throw new RecurrenceException('Repeat every 1, 2 or 4 weeks.');
        }

        if ($end->lt($start)) {
            throw new RecurrenceException('The end date must be on or after the start date.');
        }

        if ($end->gt($start->addMonths(self::MAX_MONTHS))) {
            throw new RecurrenceException('Repeats can run for at most '.self::MAX_MONTHS.' months. Pick an earlier end date.');
        }

        $weekZero = $start->startOfWeek(CarbonInterface::MONDAY);
        $dates = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $weekdays, true)) {
                continue;
            }

            $weekIndex = intdiv($weekZero->diffInDays($day->startOfWeek(CarbonInterface::MONDAY)), 7);

            if ($weekIndex % $intervalWeeks !== 0) {
                continue;
            }

            $dates[] = $day;

            if (count($dates) > self::MAX_OCCURRENCES) {
                throw new RecurrenceException('That would create more than '.self::MAX_OCCURRENCES.' sessions. Pick an earlier end date or fewer days.');
            }
        }

        return $dates;
    }

    /**
     * @param  list<int>  $weekdays
     */
    public static function describe(int $intervalWeeks, array $weekdays, CarbonInterface|string|null $endsOn = null): string
    {
        $days = collect($weekdays)->map(fn ($d) => (int) $d)->sort()->map(fn ($d) => self::WEEKDAYS[$d] ?? '')->filter();
        $dayText = $days->count() > 1 ? $days->slice(0, -1)->join(', ').' & '.$days->last() : ($days->first() ?? '');
        $text = (self::INTERVALS[$intervalWeeks] ?? 'Every week').' on '.$dayText;

        return $endsOn ? $text.' until '.CarbonImmutable::parse($endsOn)->format('M j, Y') : $text;
    }
}
