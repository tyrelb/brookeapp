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
use Illuminate\Support\Str;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToTrainer, HasFactory;

    protected $fillable = [
        'user_id',
        'plan_id',
        'gym_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'status',
        'notes',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'started_at' => 'date',
        ];
    }

    /** Families hold several people on one wallet; everyone else holds one. */
    public const MAX_MEMBERS = 10;

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** The gym this client usually trains at; pre-selected when they're added to a session. */
    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('transacted_on')->latest('id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(SessionAttendee::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('issued_on')->latest('id');
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

    public function isOnFamilyPlan(): bool
    {
        return $this->plan?->isFamily() ?? false;
    }

    /** Members of a family client, in the order the trainer arranged them. */
    public function members(): HasMany
    {
        return $this->hasMany(FamilyMember::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('active', true);
    }

    /** Deposits money and is charged per session — pay-as-you-go or family. */
    public function isOnWalletPlan(): bool
    {
        return $this->plan?->usesWallet() ?? false;
    }

    /**
     * Fitness Wallet balance: sum of all non-voided ledger rows.
     * Positive = prepaid credit; negative = amount owing.
     */
    public function balance(): float
    {
        return round((float) $this->transactions()->active()->sum('amount'), 2);
    }

    /**
     * Magic-link token for the client's read-only Fitness Wallet page. Created on first use.
     */
    public function ensurePortalToken(): string
    {
        if (! $this->portal_token) {
            $this->forceFill(['portal_token' => Str::random(48)])->save();
        }

        return $this->portal_token;
    }

    /** Invalidates any previously shared link. */
    public function regeneratePortalToken(): string
    {
        $this->forceFill(['portal_token' => Str::random(48)])->save();

        return $this->portal_token;
    }

    public function portalUrl(): string
    {
        return route('portal.wallet', ['token' => $this->ensurePortalToken()]);
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
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhereHas('members', fn (Builder $m) => $m->where('name', 'like', "%{$term}%"));
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
