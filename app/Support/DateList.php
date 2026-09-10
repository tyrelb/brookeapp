<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Pulls dates out of whatever a trainer pastes in — a column from a spreadsheet,
 * a comma-separated list, a few lines from an email. Separators do not matter;
 * anything that is not a recognisable date is handed back so it can be shown.
 */
class DateList
{
    /** Formats we accept, each anchored to a full year so nothing is guessed. */
    private const PATTERNS = [
        '\d{4}-\d{1,2}-\d{1,2}',                        // 2026-06-03
        '\d{1,2}\/\d{1,2}\/\d{4}',                      // 06/03/2026
        '[A-Za-z]{3,9}\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}', // Jun 3, 2026
        '\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9},?\s+\d{4}',    // 3 June 2026
    ];

    /**
     * @return array{dates: list<string>, ignored: list<string>}
     */
    public static function parse(string $blob): array
    {
        $pattern = '/'.implode('|', self::PATTERNS).'/';
        preg_match_all($pattern, $blob, $matches);

        $dates = [];
        foreach ($matches[0] as $match) {
            $parsed = self::toDate($match);

            if ($parsed !== null) {
                $dates[$parsed] = true;
            }
        }

        // Whatever is left once the dates and separators are removed did not parse.
        $leftovers = preg_split('/[\s,;|]+/', trim((string) preg_replace($pattern, ' ', $blob))) ?: [];

        return [
            'dates' => array_keys($dates),
            'ignored' => array_values(array_filter($leftovers, fn (string $token) => $token !== '')),
        ];
    }

    private static function toDate(string $value): ?string
    {
        $value = preg_replace('/(\d{1,2})(st|nd|rd|th)/i', '$1', trim($value)) ?? $value;

        try {
            // m/d/Y, matching how North American exports write dates.
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
                return CarbonImmutable::createFromFormat('!m/d/Y', $value)->toDateString();
            }

            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
