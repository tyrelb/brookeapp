<?php

namespace App\Models;

use App\Enums\SessionStatus;
use App\Models\Concerns\BelongsToTrainer;
use Carbon\Carbon;
use Database\Factories\TrainingSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TrainingSession extends Model
{
    /** @use HasFactory<TrainingSessionFactory> */
    use BelongsToTrainer, HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'gym_id',
        'gym_billable',
        'session_series_id',
        'starts_at',
        'duration_minutes',
        'status',
        'completed_at',
        'notes',
        'ics_uid',
        'ics_sequence',
        'invites_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => SessionStatus::class,
            'duration_minutes' => 'integer',
            'gym_billable' => 'boolean',
            'ics_sequence' => 'integer',
            'invites_sent_at' => 'datetime',
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

    public function series(): BelongsTo
    {
        return $this->belongsTo(SessionSeries::class, 'session_series_id');
    }

    public function isInSeries(): bool
    {
        return $this->session_series_id !== null;
    }

    /** Later scheduled sessions of the same series (this one excluded). */
    public function followingInSeries(): Collection
    {
        if (! $this->series) {
            return collect();
        }

        return $this->series->scheduledFrom($this)->reject(fn (TrainingSession $s) => $s->is($this))->values();
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(SessionAttendee::class);
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'session_attendees')
            ->withPivot(['attended', 'subtotal', 'gst_amount', 'total'])
            ->withTimestamps();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Number of people who actually attended; drives the per-person rate tier.
     */
    public function headcount(): int
    {
        return $this->attendees->where('attended', true)->count();
    }

    public function isCompleted(): bool
    {
        return $this->status === SessionStatus::Completed;
    }

    public function isScheduled(): bool
    {
        return $this->status === SessionStatus::Scheduled;
    }

    public function endsAt(): Carbon
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    public function isCancelled(): bool
    {
        return $this->status === SessionStatus::Cancelled;
    }

    public function invitesWereSent(): bool
    {
        return $this->invites_sent_at !== null;
    }

    /**
     * Stable identifier for calendar invites so updates and cancellations replace the original event.
     */
    public function ensureIcsUid(): string
    {
        if (! $this->ics_uid) {
            $this->forceFill(['ics_uid' => (string) Str::uuid().'@brookeapp'])->save();
        }

        return $this->ics_uid;
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', SessionStatus::Completed->value);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('starts_at', [$from, $to]);
    }
}
