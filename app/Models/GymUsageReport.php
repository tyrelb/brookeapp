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

    /**
     * The snapshot with any keys added since it was locked filled in at zero.
     *
     * Months finalized before cover sessions existed have no cover figures at all, and a
     * snapshot is a historical record that must never be rewritten. Reading them through
     * here is what keeps a single missing key in a Blade file from turning an old
     * statement into a 500.
     *
     * @return array<string, mixed>
     */
    public function normalizedSnapshot(): array
    {
        $snapshot = $this->snapshot ?? [];
        $summary = $snapshot['summary'] ?? [];

        $snapshot['cover_rows'] ??= [];
        $snapshot['summary'] = $summary + [
            'cover_sessions' => 0,
            'cover_sessions_excluded' => 0,
            'cover_people' => 0,
            'cover_by_people' => [],
            'cover_subtotal' => 0.0,
            'cover_gst' => 0.0,
            'cover_gst_rate' => null,
            'cover_total' => 0.0,
            'net_total' => $summary['total'] ?? 0.0,
        ];
        $snapshot['gym'] = ($snapshot['gym'] ?? []) + ['covers_sessions' => false];

        return $snapshot;
    }
}
