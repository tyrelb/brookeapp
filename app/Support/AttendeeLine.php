<?php

namespace App\Support;

use App\Enums\Attendance;
use App\Models\Client;
use App\Models\SessionAttendee;

/**
 * One client's presence at a session, in the form the pricer understands — built
 * either from a half-filled booking form or from rows already in the database, so
 * that both are priced by the same code.
 *
 * "Attended" is derived from the people count rather than stored beside it: a family
 * marked as attending with nobody ticked would otherwise count towards everyone
 * else's rate tier while being charged nothing.
 *
 * A late cancel is priced exactly like an attendance — same people, same tier — and only
 * carries the flag so the gym side can leave them out of the room.
 */
final class AttendeeLine
{
    /**
     * @param  list<MemberLine>  $members  empty for everyone who is not a family
     */
    private function __construct(
        public readonly Client $client,
        public readonly ?float $override,
        public readonly array $members,
        public readonly int $people,
        public readonly bool $lateCancel,
    ) {}

    /**
     * @param  list<MemberLine>  $members
     */
    public static function for(Client $client, bool $attended, ?float $override, array $members = [], bool $lateCancel = false): self
    {
        $people = $client->isOnFamilyPlan()
            ? count(array_filter($members, fn (MemberLine $member) => $member->attended))
            : ($attended ? 1 : 0);

        return new self($client, $override, $members, $people, $lateCancel && $people > 0);
    }

    /** From the Livewire state of a booking form. */
    public static function fromState(Client $client, array $state): self
    {
        $members = [];

        foreach ($state['members'] ?? [] as $memberId => $member) {
            $members[] = new MemberLine(
                (int) $memberId,
                (string) ($member['name'] ?? ''),
                (bool) ($member['attended'] ?? false),
                self::amount($member['override'] ?? null),
            );
        }

        $attendance = Attendance::fromInput($state['attendance'] ?? null);

        return self::for(
            $client,
            $attendance !== Attendance::NoShow,
            self::amount($state['override'] ?? null),
            $members,
            $attendance === Attendance::LateCancel,
        );
    }

    /** From rows already saved against a session. */
    public static function fromAttendee(SessionAttendee $attendee): self
    {
        $members = $attendee->members
            ->map(fn ($member) => new MemberLine(
                (int) $member->family_member_id,
                (string) $member->member_name,
                (bool) $member->attended,
                $member->price_override !== null ? (float) $member->price_override : null,
            ))
            ->values()
            ->all();

        return self::for(
            $attendee->client,
            (bool) $attendee->attended,
            $attendee->price_override !== null ? (float) $attendee->price_override : null,
            $members,
            (bool) $attendee->late_cancelled,
        );
    }

    public function attended(): bool
    {
        return $this->people > 0;
    }

    public function isFamily(): bool
    {
        return $this->client->isOnFamilyPlan();
    }

    /** @return list<MemberLine> */
    public function attendingMembers(): array
    {
        return array_values(array_filter($this->members, fn (MemberLine $member) => $member->attended));
    }

    private static function amount(mixed $value): ?float
    {
        return ($value === null || $value === '' || ! is_numeric($value)) ? null : (float) $value;
    }
}
