<?php

namespace App\Models;

use Database\Factories\PlanRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanRate extends Model
{
    /** @use HasFactory<PlanRateFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'service_id',
        'headcount',
        'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'headcount' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
