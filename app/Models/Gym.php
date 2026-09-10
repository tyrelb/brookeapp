<?php

namespace App\Models;

use App\Enums\GymBillingModel;
use App\Models\Concerns\BelongsToTrainer;
use Database\Factories\GymFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A facility the trainer rents space at. The gym bills the trainer, not the client.
 */
class Gym extends Model
{
    /** @use HasFactory<GymFactory> */
    use BelongsToTrainer, HasFactory;

    public const MAX_ACTIVE = 3;

    public const MAX_PEOPLE = 10;

    /** Default hourly rate card by group size, before GST. A 60-minute session bills exactly these. */
    public const DEFAULT_RATES = [1 => 18, 2 => 26, 3 => 35, 4 => 41, 5 => 52, 6 => 70, 7 => 70, 8 => 70, 9 => 70, 10 => 70];

    /** What the gym pays the trainer to cover its own clients, per session, before the TRAINER's GST. */
    public const DEFAULT_COVER_RATES = [1 => 50, 2 => 70];

    protected $fillable = [
        'user_id',
        'name',
        'billing_model',
        'monthly_fee',
        'usage_rates',
        'cover_rates',
        'charges_gst',
        'gst_rate',
        'is_default',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'billing_model' => GymBillingModel::class,
            'monthly_fee' => 'decimal:2',
            'usage_rates' => 'array',
            'cover_rates' => 'array',
            'charges_gst' => 'boolean',
            'gst_rate' => 'decimal:2',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function usageReports(): HasMany
    {
        return $this->hasMany(GymUsageReport::class);
    }

    public function chargesUsage(): bool
    {
        return $this->billing_model->includesUsage();
    }

    public function chargesMonthly(): bool
    {
        return $this->billing_model->includesMonthly();
    }

    /**
     * The gym's hourly rate for a group of the given size (before GST).
     * Uses the highest configured tier at or below the size, capped at MAX_PEOPLE.
     * Null when this gym doesn't charge for usage.
     *
     * This is the rate card, not a session's price: use chargeFor() for what a
     * session actually costs, because sessions bill pro-rata by how long they run.
     */
    public function rateFor(int $people): ?float
    {
        if (! $this->chargesUsage()) {
            return null;
        }

        $people = max(1, min($people, self::MAX_PEOPLE));
        $rates = collect($this->usage_rates ?? [])
            ->mapWithKeys(fn ($price, $size) => [(int) $size => $price])
            ->filter(fn ($price) => $price !== null && $price !== '')
            ->sortKeys();

        $match = $rates->filter(fn ($price, $size) => $size <= $people)->last();

        return $match === null ? null : round((float) $match, 2);
    }

    /**
     * What one session of this size and length costs (before GST).
     * The rate card is hourly, so 60 minutes bills the rate exactly and 90 minutes
     * bills one and a half times it. Null when this gym doesn't charge for usage.
     */
    public function chargeFor(int $people, int $minutes): ?float
    {
        $rate = $this->rateFor($people);

        return $rate === null ? null : round($rate * max(0, $minutes) / 60, 2);
    }

    /** True once the trainer has agreed a price for covering this gym's own clients. */
    public function coversSessions(): bool
    {
        return $this->coverRateFor(1) !== null;
    }

    /**
     * What the gym pays the trainer for covering a group of this size, before the
     * TRAINER's GST — this is money coming in, not a usage charge going out.
     *
     * Two deliberate differences from rateFor(): it is not gated on the billing model,
     * because a rent-only gym can still ask the trainer to cover; and there is no
     * max(1, ...) clamp, so a session with nobody named prices at null rather than
     * silently invoicing the one-person rate.
     */
    public function coverRateFor(int $people): ?float
    {
        if ($people < 1) {
            return null;
        }

        $people = min($people, self::MAX_PEOPLE);
        $rates = collect($this->cover_rates ?? [])
            ->mapWithKeys(fn ($price, $size) => [(int) $size => $price])
            ->filter(fn ($price) => $price !== null && $price !== '')
            ->sortKeys();

        $match = $rates->filter(fn ($price, $size) => $size <= $people)->last();

        return $match === null ? null : round((float) $match, 2);
    }

    public function effectiveGstRate(): float
    {
        return $this->charges_gst ? (float) $this->gst_rate : 0.0;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
