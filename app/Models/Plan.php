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
        'package_sessions',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'type' => PlanType::class,
            'monthly_fee' => 'decimal:2',
            'billing_day' => 'integer',
            'package_sessions' => 'integer',
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
     * The service a plan is really about, when it prices more than one: the lowest id,
     * which is the earliest one the trainer set up. Used to price a package and to
     * judge how many sessions a wallet has left, both of which need one answer rather
     * than whichever rate row the database happened to return first.
     */
    public function mainServiceId(): ?int
    {
        $id = $this->rates->min('service_id');

        return $id === null ? null : (int) $id;
    }

    /** What one session costs a single person on this plan, before GST. */
    public function packageUnitPrice(): ?float
    {
        $serviceId = $this->mainServiceId();

        $price = $serviceId === null
            ? null
            : $this->rateFor($serviceId, 1)?->unit_price;

        $price ??= $this->rates->where('headcount', 1)->min('unit_price');

        return $price === null ? null : (float) $price;
    }

    /**
     * What the whole package is worth before GST, when the plan is sold as one.
     * Null when the trainer has not said how many sessions a package holds.
     */
    public function packageValue(): ?float
    {
        $unit = $this->package_sessions ? $this->packageUnitPrice() : null;

        return $unit === null ? null : round($this->package_sessions * $unit, 2);
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
