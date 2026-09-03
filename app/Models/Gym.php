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

    /** Default per-session rate card by group size, before GST. */
    public const DEFAULT_RATES = [1 => 18, 2 => 26, 3 => 35, 4 => 41, 5 => 52, 6 => 70, 7 => 70, 8 => 70, 9 => 70, 10 => 70];

    protected $fillable = [
        'user_id',
        'name',
        'billing_model',
        'monthly_fee',
        'usage_rates',
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
     * Per-session charge for a group of the given size (before GST).
     * Uses the highest configured tier at or below the size, capped at MAX_PEOPLE.
     * Null when this gym doesn't charge per session.
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

    public function effectiveGstRate(): float
    {
        return $this->charges_gst ? (float) $this->gst_rate : 0.0;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
