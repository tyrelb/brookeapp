<?php

namespace App\Support;

use App\Models\Gym;

/**
 * The gym's own clients, as typed into a cover session. They are free text on purpose:
 * they change every time the owner goes away, and they are not the trainer's clients.
 */
final class CoverNames
{
    public const MAX_LENGTH = 100;

    /**
     * @param  array<mixed>  $input
     * @return list<string>
     */
    public static function clean(array $input): array
    {
        $names = [];

        foreach ($input as $name) {
            if (! is_string($name)) {
                continue;
            }

            $name = mb_substr(trim($name), 0, self::MAX_LENGTH);

            if ($name !== '') {
                // Duplicates are kept: two people really can both be Jane, and the
                // number of names is what picks the rate tier.
                $names[] = $name;
            }
        }

        return array_slice($names, 0, Gym::MAX_PEOPLE);
    }
}
