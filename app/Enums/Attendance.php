<?php

namespace App\Enums;

/**
 * How one client took part in a session, as the trainer marks it. Stored as two flags on
 * the attendee row: `attended` (on the bill) and `late_cancelled` (on the bill, but not in
 * the room). Everything that charges money reads `attended`, so a late cancel is charged
 * like anyone who came; only the gym, which bills for bodies in the room, tells them apart.
 */
enum Attendance: string
{
    case Attended = 'attended';
    case LateCancel = 'late_cancel';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Attended => 'Attended',
            self::LateCancel => 'Missed / late cancel',
            self::NoShow => 'No-show',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Attended => 'green',
            self::LateCancel => 'amber',
            self::NoShow => 'zinc',
        };
    }

    /** A stray late-cancel flag on someone who is not on the bill is just a no-show. */
    public static function fromFlags(bool $attended, bool $lateCancelled): self
    {
        return match (true) {
            ! $attended => self::NoShow,
            $lateCancelled => self::LateCancel,
            default => self::Attended,
        };
    }

    /** Form input arrives before validation, so anything unrecognised reads as attended. */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Attended) : self::Attended;
    }
}
