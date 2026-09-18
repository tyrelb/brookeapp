<?php

namespace App\Enums;

/**
 * Derived from the invoice's linked payments, never stored — see Invoice::status().
 * A stored column would go stale the moment a linked payment is voided or edited.
 */
enum InvoiceStatus: string
{
    case Sent = 'sent';
    case PartlyPaid = 'partly_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::PartlyPaid => 'Partly paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Void => 'Void',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sent => 'blue',
            self::PartlyPaid => 'amber',
            self::Paid => 'green',
            self::Overdue => 'red',
            self::Void => 'zinc',
        };
    }

    /** Still owed: worth chasing, and worth offering on the Record payment form. */
    public function isOutstanding(): bool
    {
        return $this === self::Sent || $this === self::PartlyPaid || $this === self::Overdue;
    }
}
