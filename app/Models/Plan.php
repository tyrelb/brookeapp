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

    /** Rate tiers a plan can price: Single, Partner, Triple, Quad. */
    public const MAX_HEADCOUNT = 4;

    /** People who can be in one session — a family can bring more than there are tiers. */
    public const MAX_PEOPLE = 10;

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

    public function isFamily(): bool
    {
        return $this->type === PlanType::Family;
    }

    /** Deposits money and is charged per session — pay-as-you-go or family. */
    public function usesWallet(): bool
    {
        return $this->type->chargesPerSession();
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
     * How many people were in the room. Beyond the four named tiers it just counts,
     * because "Quad" is a lie about a session of six.
     */
    public static function headcountLabel(int $headcount): string
    {
        return match (true) {
            $headcount <= 1 => 'Single',
            $headcount === 2 => 'Partner',
            $headcount === 3 => 'Triple',
            $headcount === 4 => 'Quad',
            default => "{$headcount} people",
        };
    }

    /**
     * Which rate tier a group of this size is billed at. Groups larger than the
     * tiers go on paying the Quad rate, per rateFor()'s fallback.
     */
    public static function rateTierLabel(int $headcount): string
    {
        return self::headcountLabel(min(max(1, $headcount), self::MAX_HEADCOUNT));
    }
}
