<?php

namespace App\Models;

use App\Enums\SessionStatus;
use App\Models\Concerns\BelongsToTrainer;
use App\Support\Recurrence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A repeating booking. Every occurrence is a real TrainingSession row; this row only
 * remembers the pattern and ties the occurrences together for "this and following" edits.
 */
class SessionSeries extends Model
{
    use BelongsToTrainer;

    protected $fillable = [
        'user_id',
        'service_id',
        'gym_id',
        'starts_on',
        'ends_on',
        'time',
        'duration_minutes',
        'interval_weeks',
        'weekdays',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'duration_minutes' => 'integer',
            'interval_weeks' => 'integer',
            'weekdays' => 'array',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class)->orderBy('starts_at');
    }

    /**
     * Scheduled sessions from the anchor onwards (the anchor included): the set every
     * "this and following" operation works on. Completed, cancelled and earlier sessions are never touched.
     *
     * @return Collection<int, TrainingSession>
     */
    public function scheduledFrom(TrainingSession $anchor): Collection
    {
        return $this->sessions()
            ->where('status', SessionStatus::Scheduled->value)
            ->where('starts_at', '>=', $anchor->starts_at)
            ->with(['attendees.client', 'service', 'trainer'])
            ->get();
    }

    public function remainingCount(): int
    {
        return $this->sessions()->where('status', SessionStatus::Scheduled->value)->where('starts_at', '>=', now())->count();
    }

    /** "Every 2 weeks on Tue & Thu until Nov 12, 2026" */
    public function describe(): string
    {
        return Recurrence::describe($this->interval_weeks, $this->weekdays ?? [], $this->ends_on);
    }
}
