<?php

namespace App\Enums;

enum TransactionType: string
{
    case Payment = 'payment';
    case SessionCharge = 'session_charge';
    case MonthlyFee = 'monthly_fee';
    case Adjustment = 'adjustment';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::SessionCharge => 'Session',
            self::MonthlyFee => 'Monthly fee',
            self::Adjustment => 'Adjustment',
            self::Refund => 'Refund',
        };
    }

    /**
     * Types that represent money changing hands (as opposed to charges for services).
     */
    public function isMoneyMovement(): bool
    {
        return in_array($this, [self::Payment, self::Refund], true);
    }

    /**
     * Types that represent revenue earned (charges for services rendered).
     */
    public function isCharge(): bool
    {
        return in_array($this, [self::SessionCharge, self::MonthlyFee], true);
    }
}
