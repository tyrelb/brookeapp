<?php

namespace App\Enums;

enum GymBillingModel: string
{
    case Monthly = 'monthly';
    case MonthlyPlusUsage = 'monthly_plus_usage';
    case Usage = 'usage';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly rate only',
            self::MonthlyPlusUsage => 'Monthly rate + per-session usage',
            self::Usage => 'Per-session usage only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Monthly => 'A flat fee each month; sessions are tracked but not charged individually.',
            self::MonthlyPlusUsage => 'A flat fee each month plus a charge per session based on group size.',
            self::Usage => 'Only a charge per session based on group size.',
        };
    }

    public function includesMonthly(): bool
    {
        return $this !== self::Usage;
    }

    public function includesUsage(): bool
    {
        return $this !== self::Monthly;
    }
}
