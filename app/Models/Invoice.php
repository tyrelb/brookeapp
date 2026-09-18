<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToTrainer;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request for money, emailed to the client. The opposite direction from the ledger:
 * an invoice asks, a wallet_transaction records what actually arrived.
 *
 * Whether it is paid is never stored. It is the sum of the non-voided payments linked
 * to it, so voiding or editing one of those payments corrects the invoice for free.
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToTrainer, HasFactory;

    /** Half a cent, for the one comparison that has to happen in SQL rather than in cents. */
    public const TOLERANCE = 0.005;

    protected $fillable = [
        'user_id',
        'client_id',
        'sequence',
        'number',
        'lines',
        'subtotal',
        'gst_amount',
        'gst_rate',
        'total',
        'issued_on',
        'due_on',
        'message',
        'sent_at',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'sequence' => 'integer',
            'subtotal' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_on' => 'date',
            'due_on' => 'date',
            'sent_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Every ledger row pointing here, voided ones included, for the audit trail. */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * What actually counts towards settling this. Only payments, never voided: a
     * voided row keeps its link so the history reads straight, but stops counting.
     */
    public function payments(): HasMany
    {
        return $this->transactions()
            ->where('type', TransactionType::Payment->value)
            ->whereNull('voided_at');
    }

    /**
     * What has arrived against this invoice. Uses the eager-loaded sum from
     * scopeWithPaidAmount() when it is there, mirroring Client::balance().
     */
    public function paidAmount(): float
    {
        if (array_key_exists('paid_amount', $this->attributes)) {
            return round((float) $this->attributes['paid_amount'], 2);
        }

        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function outstandingAmount(): float
    {
        return round(max(0, (float) $this->total - $this->paidAmount()), 2);
    }

    public function status(): InvoiceStatus
    {
        if ($this->isVoided()) {
            return InvoiceStatus::Void;
        }

        $paid = $this->paidAmount();

        // In cents: the decimal:2 casts hand back strings, and a float comparison
        // here is exactly how an invoice ends up a hundredth of a cent short.
        if (self::cents($paid) >= self::cents((float) $this->total)) {
            return InvoiceStatus::Paid;
        }

        if ($paid > 0) {
            return InvoiceStatus::PartlyPaid;
        }

        if ($this->due_on && $this->due_on->isBefore(today())) {
            return InvoiceStatus::Overdue;
        }

        return InvoiceStatus::Sent;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isOutstanding(): bool
    {
        return $this->status()->isOutstanding();
    }

    /** Sums the linked payments in the query so a list does not fire one query per row. */
    public function scopeWithPaidAmount(Builder $query): Builder
    {
        return $query->withSum('payments as paid_amount', 'amount');
    }

    /** Not voided and not yet settled — the ones worth showing the client. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('voided_at')->whereRaw(
            'invoices.total > COALESCE((select sum(amount) from wallet_transactions'
            .' where wallet_transactions.invoice_id = invoices.id'
            .' and wallet_transactions.type = ?'
            .' and wallet_transactions.voided_at is null), 0) + ?',
            [TransactionType::Payment->value, self::TOLERANCE],
        );
    }
}
