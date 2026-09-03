<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTrainer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finalized month for one gym: the report exactly as it looked when the trainer locked it.
 */
class GymUsageReport extends Model
{
    use BelongsToTrainer;

    protected $fillable = [
        'user_id',
        'gym_id',
        'period',
        'finalized_at',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'finalized_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }
}
