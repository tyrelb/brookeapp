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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A request for money, emailed to the client. The opposite direction from the ledger:
 * an invoice asks, a wallet_transaction records what actually arrived.
 *
 * Whether it is paid is never stored. It is the sum of the non-voided payments linked
 * to it, so voiding or editing one of those payments corrects the invoice for free.
 *
 * Deleting is soft: the number may already be in a client's inbox, so it stays taken.
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToTrainer, HasFactory, SoftDeletes;

    /** Half a cent, for the comparisons that have to happen in SQL rather than in cents. */
    public const TOLERANCE = 0.005;

    /** What has been paid against the invoice, as SQL: the sum behind every status filter. */
    private const PAID_SQL = 'COALESCE((select sum(amount) from wallet_transactions'
        .' where wallet_transactions.invoice_id = invoices.id'
        .' and wallet_transactions.type = ?'
        .' and wallet_transactions.voided_at is null), 0)';

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
        'reminded_at',
        'reminder_count',
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
            'reminded_at' => 'datetime',
            'reminder_count' => 'integer',
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

    /** Only while it is still owed: a reminder about a settled invoice is just noise. */
    public function canBeReminded(): bool
    {
        return $this->isOutstanding();
    }

    /**
     * Only while nothing has been received. After that the ledger names it, so it can be
     * voided but not made to disappear.
     */
    public function canBeDeleted(): bool
    {
        return $this->paidAmount() <= 0;
    }

    /**
     * When it was settled: the latest payment against it. Null until it is paid in full.
     * Uses the eager-loaded max from scopeWithPaidOn() when it is there.
     */
    public function paidOn(): ?Carbon
    {
        if ($this->status() !== InvoiceStatus::Paid) {
            return null;
        }

        $date = array_key_exists('paid_on', $this->attributes)
            ? $this->attributes['paid_on']
            : $this->payments()->max('transacted_on');

        return $date ? Carbon::parse($date) : null;
    }

    /** Sums the linked payments in the query so a list does not fire one query per row. */
    public function scopeWithPaidAmount(Builder $query): Builder
    {
        return $query->withSum('payments as paid_amount', 'amount');
    }

    /** The date of the latest payment against each invoice, for "Paid Sep 21". */
    public function scopeWithPaidOn(Builder $query): Builder
    {
        return $query->withMax('payments as paid_on', 'transacted_on');
    }

    /** Not voided and not yet settled — the ones worth showing the client. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('invoices.voided_at')->whereRaw(
            'invoices.total > '.self::PAID_SQL.' + ?',
            [TransactionType::Payment->value, self::TOLERANCE],
        );
    }

    /** Outstanding and past its due date. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()
            ->whereNotNull('invoices.due_on')
            ->whereDate('invoices.due_on', '<', today());
    }

    /** Not voided, and everything asked for has arrived. */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereNull('invoices.voided_at')->whereRaw(
            'invoices.total <= '.self::PAID_SQL.' + ?',
            [TransactionType::Payment->value, self::TOLERANCE],
        );
    }

    public function scopeVoided(Builder $query): Builder
    {
        return $query->whereNotNull('invoices.voided_at');
    }

    /** By invoice number, or by the client's name or email. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('invoices.number', 'like', "%{$term}%")
            ->orWhereHas('client', fn (Builder $client) => $client->search($term)));
    }
}
