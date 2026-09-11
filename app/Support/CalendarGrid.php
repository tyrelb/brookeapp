<?php

namespace App\Support;

use App\Models\TrainingSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lays one day's sessions out on a 24-hour time grid, the way a calendar app does:
 * a session's top and height follow its start and length, so free time shows up as
 * empty space. Sessions that overlap sit side by side in equal-width columns.
 *
 * Touching sessions (one ends exactly as the next begins) share a column, as they do
 * not clash in SessionConflicts either. Cancelled sessions are placed too — the
 * calendar still shows them.
 */
class CalendarGrid
{
    private const MINUTES_IN_DAY = 1440;

    /**
     * Where each session sits in its day column, as percentages of the column.
     *
     * @param  Collection<int, TrainingSession>  $sessions  sessions that start on the same day
     * @return array<int, array{top: float, height: float, left: float, width: float}> keyed by session id
     */
    public static function place(Collection $sessions): array
    {
        $blocks = $sessions
            ->map(function (TrainingSession $session) {
                $start = self::minuteOfDay($session->starts_at);

                return [
                    'id' => $session->id,
                    'start' => $start,
                    // A late session can run past midnight; its day column stops there.
                    'end' => min(self::MINUTES_IN_DAY, $start + max(1, $session->duration_minutes)),
                ];
            })
            ->sortBy([['start', 'asc'], ['end', 'desc']])
            ->values();

        $laid = [];
        $clusterColumns = []; // cluster => how many columns its overlapping sessions needed
        $cluster = -1;
        $clusterEnd = 0;
        $columnEnds = [];     // column => minute it frees up, within the current cluster

        foreach ($blocks as $block) {
            // Everything so far has finished, so this session starts a new cluster.
            if ($block['start'] >= $clusterEnd) {
                $cluster++;
                $columnEnds = [];
            }

            $column = 0;
            while (isset($columnEnds[$column]) && $columnEnds[$column] > $block['start']) {
                $column++;
            }

            $columnEnds[$column] = $block['end'];
            $clusterEnd = max($clusterEnd, $block['end']);
            $clusterColumns[$cluster] = count($columnEnds);
            $laid[] = $block + ['column' => $column, 'cluster' => $cluster];
        }

        $placed = [];

        foreach ($laid as $block) {
            $columns = $clusterColumns[$block['cluster']];

            $placed[$block['id']] = [
                'top' => self::percent($block['start']),
                'height' => self::percent($block['end'] - $block['start']),
                'left' => round($block['column'] / $columns * 100, 4),
                'width' => round(100 / $columns, 4),
            ];
        }

        return $placed;
    }

    /**
     * The half-hour slots down a day column, which draw the grid lines and book
     * that time when clicked.
     *
     * @return array<string, string> "H:i" => "g:i a", from midnight to 11:30 pm
     */
    public static function halfHours(): array
    {
        $slots = [];

        for ($minute = 0; $minute < self::MINUTES_IN_DAY; $minute += 30) {
            $slots[sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60)] = date('g:i a', mktime(0, $minute));
        }

        return $slots;
    }

    /** How far through the day a moment falls, as a percentage — e.g. for a "now" line. */
    public static function offset(CarbonInterface $time): float
    {
        return self::percent(self::minuteOfDay($time));
    }

    private static function minuteOfDay(CarbonInterface $time): int
    {
        return $time->hour * 60 + $time->minute;
    }

    private static function percent(int $minutes): float
    {
        return round($minutes / self::MINUTES_IN_DAY * 100, 4);
    }
}
