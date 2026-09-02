<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\PlanType;
use App\Models\Concerns\BelongsToTrainer;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToTrainer, HasFactory;

    protected $fillable = [
        'user_id',
        'plan_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'status',
        'notes',
        'started_at',
        'portal_token',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'started_at' => 'date',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('transacted_on')->latest('id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(SessionAttendee::class);
    }

    public function trainingSessions(): BelongsToMany
    {
        return $this->belongsToMany(TrainingSession::class, 'session_attendees')
            ->withPivot(['attended', 'subtotal', 'gst_amount', 'total'])
            ->withTimestamps();
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    public function isActive(): bool
    {
        return $this->status === ClientStatus::Active;
    }

    public function isOnMonthlyPlan(): bool
    {
        return $this->plan?->type === PlanType::Monthly;
    }

    public function isOnWalletPlan(): bool
    {
        return $this->plan?->type === PlanType::Wallet;
    }

    /**
     * Fitness Wallet balance: sum of all non-voided ledger rows.
     * Positive = prepaid credit; negative = amount owing.
     */
    public function balance(): float
    {
        return round((float) $this->transactions()->active()->sum('amount'), 2);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ClientStatus::Active->value);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    /**
     * Adds a `balance` column (sum of non-voided ledger amounts) to a client query.
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->withSum([
            'transactions as balance' => fn ($q) => $q->whereNull('voided_at'),
        ], 'amount');
    }
}
