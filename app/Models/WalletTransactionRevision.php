<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for a ledger row: one entry per change, never edited or deleted.
 * The ledger itself stays correctable, but every correction leaves a record of
 * who changed what, when, and why.
 */
class WalletTransactionRevision extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_VOIDED = 'voided';

    /** Columns worth auditing, in display order, with their labels. */
    public const TRACKED = [
        'transacted_on' => 'Date',
        'description' => 'Description',
        'payment_method' => 'Method',
        'reference' => 'Reference',
        'subtotal' => 'Before GST',
        'gst_amount' => 'GST',
        'amount' => 'Amount',
    ];

    protected $fillable = [
        'wallet_transaction_id',
        'changed_by',
        'action',
        'changed_fields',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
        ];
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATED => 'Created',
            self::ACTION_VOIDED => 'Voided',
            default => 'Edited',
        };
    }

    /**
     * Human-readable change lines, e.g. "Amount: $300.00 → $325.00".
     *
     * @return list<string>
     */
    public function summaryLines(): array
    {
        $lines = [];

        foreach (self::TRACKED as $field => $label) {
            if (! isset($this->changed_fields[$field])) {
                continue;
            }

            $change = $this->changed_fields[$field];
            $lines[] = sprintf(
                '%s: %s → %s',
                $label,
                self::formatValue($field, $change['from'] ?? null),
                self::formatValue($field, $change['to'] ?? null),
            );
        }

        return $lines;
    }

    public static function formatValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($field) {
            'amount', 'subtotal', 'gst_amount' => money((float) $value),
            'transacted_on' => CarbonImmutable::parse((string) $value)->format('M j, Y'),
            'payment_method' => PaymentMethod::tryFrom((string) $value)?->label() ?? (string) $value,
            default => (string) $value,
        };
    }
}
