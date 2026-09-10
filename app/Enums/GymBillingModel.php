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
            self::MonthlyPlusUsage => 'Monthly rate + hourly usage',
            self::Usage => 'Hourly usage only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Monthly => 'A flat fee each month; sessions are tracked but not charged individually.',
            self::MonthlyPlusUsage => 'A flat fee each month plus an hourly charge for each session, based on group size and how long it runs.',
            self::Usage => 'Only an hourly charge for each session, based on group size and how long it runs.',
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
