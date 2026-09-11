<?php

use App\Models\TrainingSession;
use App\Support\CalendarGrid;
use Illuminate\Support\Carbon;

/** An unsaved session on 15 September — placing sessions needs no database. */
function gridSession(int $id, string $time, int $minutes): TrainingSession
{
    return (new TrainingSession)->forceFill(['id' => $id, 'starts_at' => "2026-09-15 {$time}:00", 'duration_minutes' => $minutes]);
}

it('places a session at its time of day across the whole column', function () {
    expect(CalendarGrid::place(collect([gridSession(1, '06:00', 90)])))->toBe([
        1 => ['top' => 25.0, 'height' => 6.25, 'left' => 0.0, 'width' => 100.0],
    ]);
});

it('puts overlapping sessions side by side', function () {
    $placed = CalendarGrid::place(collect([gridSession(1, '09:00', 60), gridSession(2, '09:30', 60)]));

    expect($placed[1])->toMatchArray(['left' => 0.0, 'width' => 50.0])
        ->and($placed[2])->toMatchArray(['left' => 50.0, 'width' => 50.0]);
});

it('reuses a column once its session has ended, keeping the whole overlapping run the same width', function () {
    // B overlaps both A and C, but A ends exactly as C starts, so C drops back into A's column.
    $placed = CalendarGrid::place(collect([
        gridSession(3, '10:00', 30),
        gridSession(1, '09:00', 60),
        gridSession(2, '09:30', 90),
    ]));

    expect($placed[1])->toMatchArray(['left' => 0.0, 'width' => 50.0])
        ->and($placed[2])->toMatchArray(['left' => 50.0, 'width' => 50.0])
        ->and($placed[3])->toMatchArray(['left' => 0.0, 'width' => 50.0]);
});

it('gives back-to-back sessions the full width', function () {
    $placed = CalendarGrid::place(collect([gridSession(1, '09:00', 60), gridSession(2, '10:00', 60), gridSession(3, '14:00', 30)]));

    expect(array_column($placed, 'width'))->toBe([100.0, 100.0, 100.0])
        ->and($placed[2]['top'])->toEqualWithDelta($placed[1]['top'] + $placed[1]['height'], 0.001);
});

it('stops a late session at midnight', function () {
    expect(CalendarGrid::place(collect([gridSession(1, '23:00', 120)]))[1])
        ->toMatchArray(['top' => 95.8333, 'height' => 4.1667]);
});

it('says how far through the day a moment falls', function () {
    expect(CalendarGrid::offset(Carbon::parse('2026-09-15 12:00')))->toBe(50.0)
        ->and(CalendarGrid::offset(Carbon::parse('2026-09-15 18:45')))->toBe(78.125);
});
