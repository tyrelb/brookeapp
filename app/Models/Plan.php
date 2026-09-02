<?php

namespace App\Models;

use App\Enums\PlanType;
use App\Models\Concerns\BelongsToTrainer;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use BelongsToTrainer, HasFactory;

    public const MAX_HEADCOUNT = 4;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'monthly_fee',
        'billing_day',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'type' => PlanType::class,
            'monthly_fee' => 'decimal:2',
            'billing_day' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(PlanRate::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function isMonthly(): bool
    {
        return $this->type === PlanType::Monthly;
    }

    public function isWallet(): bool
    {
        return $this->type === PlanType::Wallet;
    }

    /**
     * Find the per-person rate for a service at a given headcount.
     * Falls back to the largest headcount tier at or below the requested one.
     */
    public function rateFor(Service|int $service, int $headcount): ?PlanRate
    {
        $serviceId = $service instanceof Service ? $service->id : $service;

        return $this->rates
            ->where('service_id', $serviceId)
            ->where('headcount', '<=', $headcount)
            ->sortByDesc('headcount')
            ->first();
    }

    /**
     * Human label for a headcount tier.
     */
    public static function headcountLabel(int $headcount): string
    {
        return match (true) {
            $headcount <= 1 => 'Single',
            $headcount === 2 => 'Partner',
            $headcount === 3 => 'Triple',
            default => 'Quad',
        };
    }
}
