<?php

namespace App\Enums;

enum PlanType: string
{
    case Monthly = 'monthly';
    case Wallet = 'wallet';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly membership',
            self::Wallet => 'Pay-as-you-go (Fitness Wallet)',
        };
    }
}
