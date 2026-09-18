<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToTrainer;
use Database\Factories\WalletTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only Fitness Wallet ledger. Rows are never deleted; mistakes are voided
 * (voided_at) and excluded from balances and reports. Rows may be corrected in
 * place, and every correction is written to the row's revision history.
 */
class WalletTransaction extends Model
{
    /** @use HasFactory<WalletTransactionFactory> */
    use BelongsToTrainer, HasFactory;

    protected $fillable = [
        'user_id',
        'client_id',
        'type',
        'amount',
        'subtotal',
        'gst_amount',
        'payment_method',
        'reference',
        'transacted_on',
        'billing_period',
        'training_session_id',
        'invoice_id',
        'description',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'transacted_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (WalletTransaction $transaction) {
            $transaction->recordRevision(WalletTransactionRevision::ACTION_CREATED);
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    /** The request this money answers, when the trainer said so. Usually null. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<WalletTransactionRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(WalletTransactionRevision::class)->oldest('id');
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function recordRevision(string $action, array $changes = [], ?string $reason = null): WalletTransactionRevision
    {
        return $this->revisions()->create([
            'changed_by' => auth()->id(),
            'action' => $action,
            'changed_fields' => $changes ?: null,
            'reason' => ($reason !== null && trim($reason) !== '') ? trim($reason) : null,
        ]);
    }

    /** Session charges follow their session; everything else can be corrected in place. */
    public function isEditable(): bool
    {
        return ! $this->isVoided() && $this->type !== TransactionType::SessionCharge;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isCredit(): bool
    {
        return (float) $this->amount > 0;
    }

    /** Non-voided rows. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeOfType(Builder $query, TransactionType ...$types): Builder
    {
        return $query->whereIn('type', array_map(fn (TransactionType $t) => $t->value, $types));
    }

    public function scopeInPeriod(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('transacted_on', [$from, $to]);
    }
}
