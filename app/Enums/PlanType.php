<?php

namespace App\Enums;

enum PlanType: string
{
    case Monthly = 'monthly';
    case Wallet = 'wallet';
    case Family = 'family';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly membership',
            self::Wallet => 'Pay-as-you-go (Fitness Wallet)',
            self::Family => 'Family (one shared Fitness Wallet)',
        };
    }

    /**
     * Plans whose clients deposit money and are charged per session, as opposed to
     * paying a flat monthly fee. Family plans work the same way, for several people.
     */
    public function chargesPerSession(): bool
    {
        return $this !== self::Monthly;
    }
}
