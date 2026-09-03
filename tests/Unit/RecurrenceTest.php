<?php

use App\Exceptions\RecurrenceException;
use App\Support\Recurrence;

$dates = fn (array $list) => array_map(fn ($d) => $d->toDateString(), $list);

it('expands a weekly pattern on one weekday, end date inclusive', function () use ($dates) {
    // 2026-09-08 is a Tuesday.
    expect($dates(Recurrence::occurrences('2026-09-08', '2026-09-29', [2])))
        ->toBe(['2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29']);
});

it('expands two weekdays and skips a start date that is not a chosen day', function () use ($dates) {
    // Start on Monday 2026-09-07, repeat Tue & Thu.
    expect($dates(Recurrence::occurrences('2026-09-07', '2026-09-18', [2, 4])))
        ->toBe(['2026-09-08', '2026-09-10', '2026-09-15', '2026-09-17']);
});

it('aligns every-2-weeks to the start week', function () use ($dates) {
    expect($dates(Recurrence::occurrences('2026-09-08', '2026-10-13', [2], 2)))
        ->toBe(['2026-09-08', '2026-09-22', '2026-10-06']);
});

it('describes a pattern', function () {
    expect(Recurrence::describe(1, [2, 4], '2026-11-12'))->toBe('Every week on Tue & Thu until Nov 12, 2026')
        ->and(Recurrence::describe(2, [1], null))->toBe('Every 2 weeks on Mon')
        ->and(Recurrence::describe(4, [1, 3, 5], '2026-12-01'))->toBe('Every 4 weeks on Mon, Wed & Fri until Dec 1, 2026');
});

it('rejects bad input and enforces the limits', function () {
    expect(fn () => Recurrence::occurrences('2026-09-08', '2026-09-29', []))->toThrow(RecurrenceException::class)
        ->and(fn () => Recurrence::occurrences('2026-09-08', '2026-09-01', [2]))->toThrow(RecurrenceException::class, 'end date')
        ->and(fn () => Recurrence::occurrences('2026-09-08', '2027-09-09', [2]))->toThrow(RecurrenceException::class, '12 months')
        ->and(fn () => Recurrence::occurrences('2026-09-08', '2027-03-08', [1, 2, 3, 4, 5, 6, 7]))->toThrow(RecurrenceException::class, '100 sessions')
        ->and(fn () => Recurrence::occurrences('2026-09-08', '2026-09-29', [2], 3))->toThrow(RecurrenceException::class);
});
