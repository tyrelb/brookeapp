<?php

namespace App\Enums;

/**
 * Card methods (Visa / Mastercard) are intentionally absent for now.
 * Add new cases here when the trainer wants to accept them.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case ETransfer = 'etransfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
            self::ETransfer => 'e-Transfer',
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $method) => [$method->value => $method->label()])
            ->all();
    }
}
