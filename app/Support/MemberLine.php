<?php

namespace App\Support;

/** One family member's presence at a session. */
final class MemberLine
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $attended,
        public readonly ?float $override,
    ) {}
}
